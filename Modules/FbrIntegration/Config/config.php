<?php

return [
    'name' => 'FbrIntegration',
    'module_version' => '1.0.0',
    
    // FBR API URLs
    'api' => [
        'sandbox' => [
            'base_url' => 'https://gw.fbr.gov.pk/di_data/v1/di',
            'post_invoice' => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb',
            'validate_invoice' => 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb',
        ],
        'production' => [
            'base_url' => 'https://gw.fbr.gov.pk/di_data/v1/di',
            'post_invoice' => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata',
            'validate_invoice' => 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata',
        ],
        'reference' => [
            'base_url' => 'https://gw.fbr.gov.pk',
            'provinces' => 'https://gw.fbr.gov.pk/pdi/v1/provinces',
            'document_types' => 'https://gw.fbr.gov.pk/pdi/v1/doctypecode',
            'item_codes' => 'https://gw.fbr.gov.pk/pdi/v1/itemdesccode',
            'uom' => 'https://gw.fbr.gov.pk/pdi/v1/uom',
            'sale_type_to_rate' => 'https://gw.fbr.gov.pk/pdi/v2/SaleTypeToRate',
            'hs_uom' => 'https://gw.fbr.gov.pk/pdi/v2/HS_UOM',
            'statl' => 'https://gw.fbr.gov.pk/dist/v1/statl',
            'get_reg_type' => 'https://gw.fbr.gov.pk/dist/v1/Get_Reg_Type',
        ],
    ],
    
    // Default settings
    'defaults' => [
        'environment' => 'sandbox',
        'auto_sync' => true,
        'validate_before_post' => false,
    ],
];
