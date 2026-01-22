@extends('layouts.app')
@section('title', 'Shopify Integration')

@section('content')
<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Shopify Integration</h1>
</section>

<!-- Main content -->
<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="row">
                    <div class="col-md-12">
                        <h4>Connection Status</h4>
                        <hr>
                        
                        @if($isConnected)
                            <div class="alert alert-success">
                                <i class="fa fa-check-circle"></i> 
                                Connected to: <strong>{{ $shopDomain }}</strong>
                                @if($lastSyncAt)
                                    <br>Last sync: {{ \Carbon\Carbon::parse($lastSyncAt)->format('Y-m-d H:i:s') }}
                                @endif
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-danger" id="disconnect_btn">
                                        <i class="fa fa-unlink"></i> Disconnect
                                    </button>
                                    <button type="button" class="btn btn-info" id="test_connection_btn">
                                        <i class="fa fa-plug"></i> Test Connection
                                    </button>
                                </div>
                            </div>
                        @else
                            <div class="alert alert-warning">
                                <i class="fa fa-exclamation-triangle"></i> Not connected to Shopify
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    {!! Form::open(['url' => route('shopify.connect'), 'method' => 'post', 'id' => 'connect_form']) !!}
                                        <div class="form-group">
                                            {!! Form::label('shop_domain', 'Shop Domain') !!}
                                            <div class="input-group">
                                                {!! Form::text('shop_domain', null, ['class' => 'form-control', 'placeholder' => 'your-store.myshopify.com', 'required']) !!}
                                                <span class="input-group-btn">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="fa fa-link"></i> Connect Store
                                                    </button>
                                                </span>
                                            </div>
                                            <small class="help-block">Enter your Shopify store domain (e.g., your-store.myshopify.com)</small>
                                        </div>
                                    {!! Form::close() !!}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                @if($isConnected)
                <div class="row" style="margin-top: 20px;">
                    <div class="col-md-12">
                        <h4>Sync Settings</h4>
                        <hr>
                        
                        {!! Form::open(['url' => route('shopify.update-settings'), 'method' => 'post', 'id' => 'settings_form']) !!}
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <div class="checkbox">
                                            <label>
                                                {!! Form::checkbox('sync_enabled', 1, true, ['id' => 'sync_enabled']) !!} 
                                                Enable Sync
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <div class="checkbox">
                                            <label>
                                                {!! Form::checkbox('auto_sync_products', 1, false, ['id' => 'auto_sync_products']) !!} 
                                                Auto Sync Products
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <div class="checkbox">
                                            <label>
                                                {!! Form::checkbox('auto_sync_orders', 1, false, ['id' => 'auto_sync_orders']) !!} 
                                                Auto Sync Orders
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        {!! Form::label('sync_frequency', 'Sync Frequency') !!}
                                        {!! Form::select('sync_frequency', [
                                            'hourly' => 'Hourly',
                                            'daily' => 'Daily',
                                            'weekly' => 'Weekly'
                                        ], 'daily', ['class' => 'form-control']) !!}
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> Save Settings
                            </button>
                        {!! Form::close() !!}
                    </div>
                </div>

                <div class="row" style="margin-top: 20px;">
                    <div class="col-md-12">
                        <h4>Manual Sync</h4>
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <button type="button" class="btn btn-success" id="sync_products_btn">
                                    <i class="fa fa-refresh"></i> Sync Products from Shopify
                                </button>
                                <button type="button" class="btn btn-success" id="sync_orders_btn">
                                    <i class="fa fa-refresh"></i> Sync Orders from Shopify
                                </button>
                            </div>
                        </div>
                        
                        <div class="row" style="margin-top: 15px;">
                            <div class="col-md-12">
                                <div class="alert alert-info">
                                    <i class="fa fa-info-circle"></i> 
                                    <strong>Cleanup Duplicates:</strong> Remove duplicate Shopify orders that were synced multiple times. 
                                    This will keep the oldest transaction for each Shopify order and delete the rest.
                                </div>
                                <button type="button" class="btn btn-warning" id="cleanup_duplicates_btn">
                                    <i class="fa fa-trash"></i> Remove Duplicate Shopify Orders
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            @endcomponent
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        // Disconnect button
        $('#disconnect_btn').on('click', function() {
            if (confirm('Are you sure you want to disconnect from Shopify?')) {
                $.ajax({
                    url: '{{ route("shopify.disconnect") }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        if (response.success) {
                            toastr.success(response.msg);
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            toastr.error(response.msg);
                        }
                    }
                });
            }
        });

        // Test connection
        $('#test_connection_btn').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');
            
            $.ajax({
                url: '{{ route("shopify.test-connection") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-plug"></i> Test Connection');
                    if (response.success) {
                        toastr.success(response.msg);
                    } else {
                        toastr.error(response.msg);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html('<i class="fa fa-plug"></i> Test Connection');
                    toastr.error('Connection test failed');
                }
            });
        });

        // Sync products
        $('#sync_products_btn').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Syncing...');
            
            $.ajax({
                url: '{{ route("shopify.sync.products") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-refresh"></i> Sync Products from Shopify');
                    if (response.success) {
                        toastr.success(response.msg);
                    } else {
                        toastr.error(response.msg);
                    }
                }
            });
        });

        // Sync orders
        $('#sync_orders_btn').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Syncing...');
            
            $.ajax({
                url: '{{ route("shopify.sync.orders") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-refresh"></i> Sync Orders from Shopify');
                    if (response.success) {
                        toastr.success(response.msg);
                    } else {
                        toastr.error(response.msg);
                    }
                }
            });
        });

        // Cleanup duplicates
        $('#cleanup_duplicates_btn').on('click', function() {
            if (!confirm('Are you sure you want to remove duplicate Shopify orders? This action cannot be undone. The oldest transaction for each Shopify order will be kept, and all duplicates will be deleted.')) {
                return;
            }
            
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Cleaning up...');
            
            $.ajax({
                url: '{{ route("shopify.sync.cleanup-duplicates") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-trash"></i> Remove Duplicate Shopify Orders');
                    if (response.success) {
                        toastr.success(response.msg);
                        if (response.deleted_count > 0) {
                            toastr.info('Deleted ' + response.deleted_count + ' duplicate transaction(s) from ' + response.duplicate_groups + ' duplicate order group(s).');
                        } else {
                            toastr.info('No duplicate orders found.');
                        }
                    } else {
                        toastr.error(response.msg);
                    }
                },
                error: function(xhr) {
                    btn.prop('disabled', false).html('<i class="fa fa-trash"></i> Remove Duplicate Shopify Orders');
                    var errorMsg = 'Failed to cleanup duplicates';
                    if (xhr.responseJSON && xhr.responseJSON.msg) {
                        errorMsg = xhr.responseJSON.msg;
                    }
                    toastr.error(errorMsg);
                }
            });
        });
    });
</script>
@endsection

