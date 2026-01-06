@if(isset($view_data) && !empty($view_data))
    <button type="button" class="btn btn-sm btn-info" id="bulk_sync_to_shopify_btn" title="Sync Selected to Shopify">
        <i class="fa fa-upload"></i> Sync to Shopify
    </button>
@endif

<script type="text/javascript">
    $(document).ready(function() {
        $('#bulk_sync_to_shopify_btn').on('click', function() {
            var selectedProducts = getSelectedProducts(); // Assuming this function exists
            
            if (selectedProducts.length === 0) {
                toastr.warning('Please select products to sync');
                return;
            }
            
            if (confirm('Sync ' + selectedProducts.length + ' product(s) to Shopify?')) {
                $.ajax({
                    url: '{{ route("shopify.sync.products") }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        product_ids: selectedProducts
                    },
                    success: function(response) {
                        if (response.success) {
                            toastr.success(response.msg);
                        } else {
                            toastr.error(response.msg);
                        }
                    }
                });
            }
        });
    });
</script>

