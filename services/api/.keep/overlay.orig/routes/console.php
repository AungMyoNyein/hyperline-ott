<?php
use Illuminate\Support\Facades\Artisan;
use App\Models\User;
Artisan::command('hyperline:admin {email} {name}',function($email,$name){$password=env('HYPERLINE_INITIAL_ADMIN_PASSWORD');if(!$password||strlen($password)<16){$this->error('Set HYPERLINE_INITIAL_ADMIN_PASSWORD (minimum 16 chars)');return 1;}User::updateOrCreate(['email'=>$email],['name'=>$name,'password'=>$password,'is_admin'=>true]);$this->info('Admin created/updated; remove bootstrap password from environment after setup.');return 0;});
