@extends('layouts.app')
@section('title', __('fbrintegration::fbr.fbr_integration'))

@section('content')
<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('fbrintegration::fbr.fbr_integration')</h1>
</section>

<!-- Main content -->
<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="row">
                    <div class="col-md-12">
                        <h4>@lang('fbrintegration::fbr.connection_status')</h4>
                        <hr>
                        
                        @php
                            $isConnected = !empty($settings) && isset($settings['enabled']) && $settings['enabled'];
                            $connectedAt = $settings['connected_at'] ?? null;
                        @endphp
                        
                        @if($isConnected)
                            <div class="alert alert-success">
                                <i class="fa fa-check-circle"></i> 
                                <strong>@lang('fbrintegration::fbr.connected')</strong>
                                @if($connectedAt)
                                    <br>@lang('fbrintegration::fbr.last_synced'): {{ \Carbon\Carbon::parse($connectedAt)->format('Y-m-d H:i:s') }}
                                @endif
                                <br>
                                <small>
                                    <strong>Environment:</strong> {{ strtoupper($settings['environment'] ?? 'sandbox') }}
                                    <br>
                                    <strong>Seller NTN:</strong> {{ $settings['seller_ntn'] ?? 'N/A' }}
                                </small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-danger" id="disconnect_btn">
                                        <i class="fa fa-unlink"></i> @lang('fbrintegration::fbr.disconnect_fbr')
                                    </button>
                                    <button type="button" class="btn btn-info" id="test_connection_btn">
                                        <i class="fa fa-plug"></i> @lang('fbrintegration::fbr.test_connection')
                                    </button>
                                </div>
                            </div>
                        @else
                            <div class="alert alert-warning">
                                <i class="fa fa-exclamation-triangle"></i> @lang('fbrintegration::fbr.disconnected')
                            </div>
                        @endif
                    </div>
                </div>

                <div class="row" style="margin-top: 20px;">
                    <div class="col-md-12">
                        <h4>{{ $isConnected ? 'Update' : 'Connect' }} FBR Settings</h4>
                        <hr>
                        
                        {!! Form::open(['url' => route('fbr.connect'), 'method' => 'post', 'id' => 'connect_form']) !!}
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {!! Form::label('security_token', __('fbrintegration::fbr.security_token') . ' *') !!}
                                        {!! Form::text('security_token', null, [
                                            'class' => 'form-control', 
                                            'placeholder' => 'Enter FBR Security Token',
                                            'required',
                                            'id' => 'security_token'
                                        ]) !!}
                                        <small class="help-block">@lang('fbrintegration::fbr.security_token_help')</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {!! Form::label('environment', __('fbrintegration::fbr.environment') . ' *') !!}
                                        {!! Form::select('environment', [
                                            'sandbox' => __('fbrintegration::fbr.sandbox'),
                                            'production' => __('fbrintegration::fbr.production')
                                        ], $settings['environment'] ?? 'sandbox', [
                                            'class' => 'form-control',
                                            'required',
                                            'id' => 'environment'
                                        ]) !!}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {!! Form::label('seller_ntn', __('fbrintegration::fbr.seller_ntn') . ' *') !!}
                                        {!! Form::text('seller_ntn', $settings['seller_ntn'] ?? null, [
                                            'class' => 'form-control', 
                                            'placeholder' => '7 or 13 digit NTN/CNIC',
                                            'required',
                                            'minlength' => 7,
                                            'maxlength' => 13,
                                            'pattern' => '[0-9]{7,13}'
                                        ]) !!}
                                        <small class="help-block">@lang('fbrintegration::fbr.seller_ntn_help')</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {!! Form::label('scenario_id', __('fbrintegration::fbr.scenario_id')) !!}
                                        {!! Form::text('scenario_id', $settings['scenario_id'] ?? 'SN001', [
                                            'class' => 'form-control', 
                                            'placeholder' => 'SN001',
                                            'id' => 'scenario_id'
                                        ]) !!}
                                        <small class="help-block">@lang('fbrintegration::fbr.scenario_id_help')</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="checkbox">
                                            <label>
                                                {!! Form::checkbox('auto_sync', 1, $settings['auto_sync'] ?? true, ['id' => 'auto_sync']) !!} 
                                                @lang('fbrintegration::fbr.auto_sync')
                                            </label>
                                        </div>
                                        <small class="help-block">@lang('fbrintegration::fbr.auto_sync_help')</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="checkbox">
                                            <label>
                                                {!! Form::checkbox('validate_before_post', 1, $settings['validate_before_post'] ?? false, ['id' => 'validate_before_post']) !!} 
                                                @lang('fbrintegration::fbr.validate_before_post')
                                            </label>
                                        </div>
                                        <small class="help-block">@lang('fbrintegration::fbr.validate_before_post_help')</small>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> {{ $isConnected ? __('fbrintegration::fbr.save_settings') : __('fbrintegration::fbr.connect_fbr') }}
                            </button>
                        {!! Form::close() !!}
                    </div>
                </div>
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
            if (confirm('Are you sure you want to disconnect from FBR?')) {
                $.ajax({
                    url: '{{ route("fbr.disconnect") }}',
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
                    },
                    error: function(xhr) {
                        var errorMsg = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Failed to disconnect';
                        toastr.error(errorMsg);
                    }
                });
            }
        });

        // Test connection
        $('#test_connection_btn').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');
            
            $.ajax({
                url: '{{ route("fbr.test-connection") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    btn.prop('disabled', false).html('<i class="fa fa-plug"></i> @lang("fbrintegration::fbr.test_connection")');
                    if (response.success) {
                        toastr.success(response.message || 'Connection successful');
                    } else {
                        toastr.error(response.message || 'Connection failed');
                    }
                },
                error: function(xhr) {
                    btn.prop('disabled', false).html('<i class="fa fa-plug"></i> @lang("fbrintegration::fbr.test_connection")');
                    var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Connection test failed';
                    toastr.error(errorMsg);
                }
            });
        });

        // Show/hide scenario ID based on environment
        $('#environment').on('change', function() {
            if ($(this).val() === 'production') {
                $('#scenario_id').closest('.form-group').hide();
            } else {
                $('#scenario_id').closest('.form-group').show();
            }
        }).trigger('change');

        // Connect form submission
        $('#connect_form').on('submit', function(e) {
            var securityToken = $('#security_token').val();
            if (!securityToken || securityToken.trim() === '') {
                e.preventDefault();
                toastr.error('Security token is required');
                return false;
            }
        });
    });
</script>
@endsection
