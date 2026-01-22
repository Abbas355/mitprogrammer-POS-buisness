# FBR Integration Module

FBR Digital Invoicing Integration Module for UltimatePOS - Pakistan

## Features

- Connect/disconnect FBR integration per store
- Automatic invoice synchronization to FBR when sales are completed
- Support for Sales Invoice and Debit Note
- Secure storage of FBR credentials
- Sandbox and Production environment support
- FBR invoice number display on POS invoices
- Manual invoice sync option
- Comprehensive error handling and logging

## Installation

1. The module is located in `Modules/FbrIntegration/`
2. Go to Settings > Modules
3. Find "FbrIntegration" and click Install
4. Run migrations: `php artisan migrate`

## Configuration

1. Go to FBR Integration Settings
2. Enter your FBR Security Token (Bearer token)
3. Select Environment (Sandbox/Production)
4. Enter Seller NTN/CNIC (7 or 13 digits)
5. Configure auto-sync and validation options
6. Click "Connect FBR"

## Usage

Once connected, all final sales invoices will automatically sync to FBR when completed. The FBR invoice number will be displayed on the POS invoice.

## Requirements

- PHP 8.0+
- Guzzle HTTP Client
- Valid FBR Security Token from PRAL

## Support

For issues or questions, please contact support.
