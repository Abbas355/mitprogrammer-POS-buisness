<?php

namespace Modules\FbrIntegration\Services;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\Transaction;
use App\TransactionSellLine;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Carbon\Carbon;

class FbrApiService
{
    protected $client;
    protected $business;
    protected $settings;
    protected $environment;
    protected $securityToken;
    protected $baseUrl;

    public function __construct($businessId)
    {
        $this->business = Business::find($businessId);
        
        if (!$this->business || !$this->business->fbr_api_settings) {
            throw new \Exception('FBR API settings not configured for this business');
        }

        $this->settings = is_string($this->business->fbr_api_settings) 
            ? json_decode($this->business->fbr_api_settings, true) 
            : $this->business->fbr_api_settings;

        if (empty($this->settings) || !isset($this->settings['enabled']) || !$this->settings['enabled']) {
            throw new \Exception('FBR integration is not enabled for this business');
        }

        $this->environment = $this->settings['environment'] ?? 'sandbox';
        $this->securityToken = $this->settings['security_token'] ?? null;
        
        if (!$this->securityToken) {
            throw new \Exception('FBR security token not configured');
        }

        // Decrypt token if encrypted
        try {
            $this->securityToken = Crypt::decryptString($this->securityToken);
        } catch (\Exception $e) {
            // If decryption fails, assume it's not encrypted
        }

        $config = config('fbrintegration.api');
        $envConfig = $config[$this->environment] ?? $config['sandbox'];
        $this->baseUrl = $envConfig['base_url'] ?? 'https://gw.fbr.gov.pk/di_data/v1/di';

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->securityToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
            'verify' => true,
        ]);
    }

    /**
     * Post invoice data to FBR
     *
     * @param Transaction $transaction
     * @return array
     */
    public function postInvoiceData(Transaction $transaction)
    {
        try {
            $invoiceData = $this->prepareInvoiceData($transaction);
            
            $endpoint = $this->environment === 'production' 
                ? config('fbrintegration.api.production.post_invoice')
                : config('fbrintegration.api.sandbox.post_invoice');

            $response = $this->client->post($endpoint, [
                'json' => $invoiceData,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'data' => $responseData,
                'invoice_number' => $responseData['invoiceNumber'] ?? null,
                'status' => $responseData['validationResponse']['status'] ?? null,
                'status_code' => $responseData['validationResponse']['statusCode'] ?? null,
            ];

        } catch (RequestException $e) {
            $errorMessage = 'FBR API Error';
            $statusCode = 0;
            
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();
                
                try {
                    $errorData = json_decode($body, true);
                    $errorMessage = $errorData['validationResponse']['error'] ?? $errorData['error'] ?? $body;
                } catch (\Exception $ex) {
                    $errorMessage = $body;
                }
            } else {
                $errorMessage = $e->getMessage();
            }

            Log::error('FBR API Post Invoice Error', [
                'transaction_id' => $transaction->id,
                'status_code' => $statusCode,
                'error' => $errorMessage,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
                'status_code' => $statusCode,
            ];

        } catch (\Exception $e) {
            Log::error('FBR API Post Invoice Exception', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate invoice data before posting
     *
     * @param Transaction $transaction
     * @return array
     */
    public function validateInvoiceData(Transaction $transaction)
    {
        try {
            $invoiceData = $this->prepareInvoiceData($transaction);
            
            $endpoint = $this->environment === 'production' 
                ? config('fbrintegration.api.production.validate_invoice')
                : config('fbrintegration.api.sandbox.validate_invoice');

            $response = $this->client->post($endpoint, [
                'json' => $invoiceData,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'data' => $responseData,
                'status' => $responseData['validationResponse']['status'] ?? null,
                'status_code' => $responseData['validationResponse']['statusCode'] ?? null,
            ];

        } catch (RequestException $e) {
            $errorMessage = 'FBR API Validation Error';
            
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $body = $response->getBody()->getContents();
                
                try {
                    $errorData = json_decode($body, true);
                    $errorMessage = $errorData['validationResponse']['error'] ?? $errorData['error'] ?? $body;
                } catch (\Exception $ex) {
                    $errorMessage = $body;
                }
            } else {
                $errorMessage = $e->getMessage();
            }

            return [
                'success' => false,
                'error' => $errorMessage,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prepare invoice data in FBR format
     *
     * @param Transaction $transaction
     * @return array
     */
    protected function prepareInvoiceData(Transaction $transaction)
    {
        $location = $transaction->location;
        $business = $transaction->business;
        $contact = $transaction->contact;
        
        // Get seller information from business/location
        $sellerNTN = $this->settings['seller_ntn'] ?? $business->tax_number_1 ?? '';
        $sellerBusinessName = $business->name ?? '';
        $sellerProvince = $this->getProvinceFromLocation($location);
        $sellerAddress = $this->getAddressFromLocation($location);

        // Get buyer information from contact
        $buyerNTN = $contact->tax_number ?? $contact->contact_id ?? '';
        $buyerBusinessName = $contact->supplier_business_name ?? $contact->name ?? '';
        $buyerProvince = $contact->state ?? $sellerProvince;
        $buyerAddress = $this->getContactAddress($contact);
        $buyerRegistrationType = $this->getBuyerRegistrationType($contact, $buyerNTN);

        // Determine invoice type
        $invoiceType = $transaction->type === 'sell_return' ? 'Debit Note' : 'Sale Invoice';
        
        // Prepare items
        $items = [];
        foreach ($transaction->sell_lines as $index => $sellLine) {
            $product = $sellLine->product;
            $variation = $sellLine->variations;
            
            // Get HS Code from product
            $hsCode = $product->hs_code ?? '0101.2100'; // Default HS code if not set
            
            // Get tax rate
            $taxRate = $sellLine->line_tax ?? $product->product_tax;
            $ratePercent = $taxRate ? $taxRate->amount : 0;
            $rate = $ratePercent . '%';
            
            // Get UOM
            $unit = $product->unit ?? $variation->product->unit ?? null;
            $uom = $unit ? $unit->short_name : 'Numbers, pieces, units';
            
            // Calculate values
            $quantity = (float) $sellLine->quantity;
            $unitPrice = (float) $sellLine->unit_price_before_discount;
            $lineDiscount = (float) $sellLine->get_discount_amount();
            $valueSalesExcludingST = ($unitPrice * $quantity) - $lineDiscount;
            
            // Calculate tax
            $salesTaxApplicable = ($valueSalesExcludingST * $ratePercent) / 100;
            $totalValues = $valueSalesExcludingST + $salesTaxApplicable;
            
            $item = [
                'hsCode' => $hsCode,
                'productDescription' => $product->name . ($variation ? ' - ' . $variation->name : ''),
                'rate' => $rate,
                'uoM' => $uom,
                'quantity' => number_format($quantity, 4, '.', ''),
                'totalValues' => number_format($totalValues, 2, '.', ''),
                'valueSalesExcludingST' => number_format($valueSalesExcludingST, 2, '.', ''),
                'fixedNotifiedValueOrRetailPrice' => number_format(0, 2, '.', ''),
                'salesTaxApplicable' => number_format($salesTaxApplicable, 2, '.', ''),
                'salesTaxWithheldAtSource' => number_format(0, 2, '.', ''),
                'extraTax' => number_format(0, 2, '.', ''),
                'furtherTax' => number_format(0, 2, '.', ''),
                'sroScheduleNo' => '',
                'fedPayable' => number_format(0, 2, '.', ''),
                'discount' => number_format($lineDiscount, 2, '.', ''),
                'saleType' => 'Goods at standard rate (default)',
                'sroItemSerialNo' => '',
            ];

            $items[] = $item;
        }

        $invoiceData = [
            'invoiceType' => $invoiceType,
            'invoiceDate' => Carbon::parse($transaction->transaction_date)->format('Y-m-d'),
            'sellerNTNCNIC' => $sellerNTN,
            'sellerBusinessName' => $sellerBusinessName,
            'sellerProvince' => $sellerProvince,
            'sellerAddress' => $sellerAddress,
            'buyerNTNCNIC' => $buyerNTN,
            'buyerBusinessName' => $buyerBusinessName,
            'buyerProvince' => $buyerProvince,
            'buyerAddress' => $buyerAddress,
            'buyerRegistrationType' => $buyerRegistrationType,
            'invoiceRefNo' => $transaction->return_parent_id ? $transaction->return_parent->invoice_no : '',
            'items' => $items,
        ];

        // Add scenario ID for sandbox (at invoice level)
        if ($this->environment === 'sandbox') {
            $invoiceData['scenarioId'] = $this->settings['scenario_id'] ?? 'SN001';
        }

        return $invoiceData;
    }

    /**
     * Get province from location
     */
    protected function getProvinceFromLocation($location)
    {
        if (!$location) {
            return 'Punjab'; // Default
        }

        $provinceMap = [
            'punjab' => 'PUNJAB',
            'sindh' => 'SINDH',
            'khyber pakhtunkhwa' => 'KHYBER PAKHTUNKHWA',
            'balochistan' => 'BALOCHISTAN',
            'gilgit baltistan' => 'GILGIT BALTISTAN',
            'azad kashmir' => 'AZAD KASHMIR',
            'islamabad' => 'ISLAMABAD',
        ];

        $state = strtolower($location->state ?? '');
        foreach ($provinceMap as $key => $value) {
            if (strpos($state, $key) !== false) {
                return $value;
            }
        }

        return 'PUNJAB'; // Default
    }

    /**
     * Get address from location
     */
    protected function getAddressFromLocation($location)
    {
        if (!$location) {
            return '';
        }

        $addressParts = array_filter([
            $location->landmark,
            $location->city,
            $location->state,
            $location->zip_code,
        ]);

        return implode(', ', $addressParts);
    }

    /**
     * Get contact address
     */
    protected function getContactAddress($contact)
    {
        if (!$contact) {
            return '';
        }

        $addressParts = array_filter([
            $contact->address_line_1,
            $contact->address_line_2,
            $contact->city,
            $contact->state,
            $contact->zip_code,
        ]);

        return implode(', ', $addressParts) ?: 'Not provided';
    }

    /**
     * Get buyer registration type
     */
    protected function getBuyerRegistrationType($contact, $buyerNTN)
    {
        // If buyer has NTN, assume registered
        if (!empty($buyerNTN) && strlen($buyerNTN) >= 7) {
            return 'Registered';
        }

        // Check if contact has registration info
        if ($contact && !empty($contact->tax_number)) {
            return 'Registered';
        }

        return 'Unregistered';
    }

    /**
     * Test connection to FBR API
     *
     * @return array
     */
    public function testConnection()
    {
        try {
            // Try to get provinces (simple API call to test connection)
            $client = new Client([
                'base_uri' => config('fbrintegration.api.reference.base_url'),
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->securityToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $response = $client->get(config('fbrintegration.api.reference.provinces'));
            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'data' => $data,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }
}
