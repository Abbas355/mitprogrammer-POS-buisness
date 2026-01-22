<div class="pos-tab-content">
	<div class="row">
	@if(!empty($modules))
		<h4>@lang('lang_v1.enable_disable_modules')</h4>
		@foreach($modules as $k => $v)
            <div class="col-sm-4">
                <div class="form-group">
                    <div class="checkbox">
                    <br>
                      <label>
                        {!! Form::checkbox('enabled_modules[]', $k,  in_array($k, $enabled_modules) , 
                        ['class' => 'input-icheck']); !!} {{$v['name']}}
                      </label>
                      @if(!empty($v['tooltip'])) @show_tooltip($v['tooltip']) @endif
                    </div>
                </div>
            </div>
        @endforeach
	@endif
	</div>
	
	{{-- Module-specific settings tabs --}}
	@if(!empty($module_business_settings_tabs))
		<hr>
		<h4>@lang('lang_v1.module_settings')</h4>
		@foreach($module_business_settings_tabs as $module_name => $tab_data)
			@if(!empty($tab_data) && is_array($tab_data) && isset($tab_data['view_path']))
				<div class="row" style="margin-top: 20px;">
					@include($tab_data['view_path'], $tab_data['view_data'] ?? [])
				</div>
			@endif
		@endforeach
	@endif
</div>