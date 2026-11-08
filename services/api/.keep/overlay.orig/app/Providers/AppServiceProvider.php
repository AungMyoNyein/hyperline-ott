<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
class AppServiceProvider extends ServiceProvider {public function register():void{} public function boot():void{Gate::define('manage-ott',fn($user)=>$user->is_admin===true);} }
