<div class="tab-pane" id="shopify_tab">
    <div class="row">
        <div class="col-md-12">
            <h4>Shopify Integration</h4>
            <hr>
            
            @php
                $product = isset($product) ? $product : null;
                $isShopifyConnected = false;
                $shopifyProductId = null;
                $shopifyVariantId = null;
                
                if ($product) {
                    $shopifyProductId = $product->shopify_product_id;
                    $shopifyVariantId = $product->variations->first()->shopify_variant_id ?? null;
                    
                    $business = \App\Business::find($product->business_id);
                    $isShopifyConnected = $business && $business->shopify_api_settings;
                }
            @endphp
            
            @if($isShopifyConnected)
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Shopify Product ID</label>
                            <input type="text" class="form-control" value="{{ $shopifyProductId }}" readonly>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Shopify Variant ID</label>
                            <input type="text" class="form-control" value="{{ $shopifyVariantId }}" readonly>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <div class="checkbox">
                                <label>
                                    {!! Form::checkbox('shopify_disable_sync', 1, $product->shopify_disable_sync ?? false, ['id' => 'shopify_disable_sync']) !!} 
                                    Disable Shopify Sync
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <button type="button" class="btn btn-primary" id="sync_to_shopify_btn" data-product-id="{{ $product->id ?? '' }}">
                            <i class="fa fa-upload"></i> Sync to Shopify
                        </button>
                        <button type="button" class="btn btn-info" id="sync_from_shopify_btn" data-product-id="{{ $product->id ?? '' }}">
                            <i class="fa fa-download"></i> Sync from Shopify
                        </button>
                    </div>
                </div>
                
                @if($product && $product->shopify_last_synced_at)
                    <div class="row" style="margin-top: 10px;">
                        <div class="col-md-12">
                            <small class="text-muted">
                                Last synced: {{ \Carbon\Carbon::parse($product->shopify_last_synced_at)->format('Y-m-d H:i:s') }}
                            </small>
                        </div>
                    </div>
                @endif
            @else
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i> Shopify is not connected. Please connect your store in Settings.
                </div>
            @endif
        </div>
    </div>
</div>

@if($isShopifyConnected)
<script type="text/javascript">
    $(document).ready(function() {
        // Sync to Shopify
        $('#sync_to_shopify_btn').on('click', function() {
            var productId = $(this).data('product-id');
            var btn = $(this);
            
            if (!productId) {
                toastr.error('Product ID not found');
                return;
            }
            
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Syncing...');
            
            $.ajax({
                url: '/shopify/sync/product/' + productId,
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-upload"></i> Sync to Shopify');
                    if (response.success) {
                        toastr.success(response.msg);
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        toastr.error(response.msg);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html('<i class="fa fa-upload"></i> Sync to Shopify');
                    toastr.error('Sync failed');
                }
            });
        });
        
        // Sync from Shopify
        $('#sync_from_shopify_btn').on('click', function() {
            toastr.info('This will sync the product data from Shopify. Please use the main sync function.');
        });
    });
</script>
@endif

