<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Response;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        # Registering further validations/changes when users are created
        \Metrogistics\AzureSocialite\UserFactory::userCallback(function($new_user){
            $new_user->description = str_replace("IATec - ", "", $new_user->description);
            $new_user->employee_id = \App\Models\Employee::where('email', $new_user->email)->value('employee_id');
        });

        Response::macro('attachment', function ($headers, $content) {        
            return Response::make($content, 200, $headers);    
        });        
    }

    
}
