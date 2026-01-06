<div class="col-md-3">
    <div class="form-group">
        <label>Shopify Sync Status</label>
        {!! Form::select('shopify_sync_status', [
            '' => 'All',
            'synced' => 'Synced with Shopify',
            'not_synced' => 'Not Synced',
            'sync_disabled' => 'Sync Disabled'
        ], request()->get('shopify_sync_status'), ['class' => 'form-control', 'id' => 'shopify_sync_status_filter']) !!}
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('#shopify_sync_status_filter').on('change', function() {
            var status = $(this).val();
            var url = new URL(window.location.href);
            
            if (status) {
                url.searchParams.set('shopify_sync_status', status);
            } else {
                url.searchParams.delete('shopify_sync_status');
            }
            
            window.location.href = url.toString();
        });
    });
</script>

