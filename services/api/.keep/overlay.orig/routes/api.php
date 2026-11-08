<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Movie;
use Illuminate\Validation\Rule;

Route::prefix('v1')->group(function(){
 Route::get('/health',fn()=>response()->json(['status'=>'ok']));
 Route::get('/movies',fn()=>Movie::where('published',true)->latest()->paginate(24));
 Route::get('/movies/{movie}',function(Movie $movie){abort_unless($movie->published,404);return $movie;});
 Route::get('/movies/{movie}/playback',function(Movie $movie){abort_unless($movie->published,404);return response()->json(['url'=>$movie->getRawOriginal('hls_url')]);}); // Phase 1 public catalog; enforce entitlements before paid launch
 Route::post('/admin/login',function(Request $r){$v=$r->validate(['email'=>'required|email','password'=>'required|string']);$u=User::where('email',$v['email'])->first();abort_unless($u&&$u->is_admin&&Hash::check($v['password'],$u->password),401,'Invalid credentials');return ['token'=>$u->createToken('cms',['cms'])->plainTextToken,'user'=>['name'=>$u->name,'email'=>$u->email]];})->middleware('throttle:6,1');
 Route::middleware('auth:sanctum')->prefix('admin')->group(function(){
  Route::middleware('can:manage-ott')->group(function(){
   Route::get('/movies',fn()=>Movie::latest()->paginate(50));
   Route::post('/movies',function(Request $r){$v=$r->validate(['title'=>'required|string|max:255','slug'=>'required|alpha_dash|unique:movies,slug','description'=>'nullable|string','poster_url'=>'nullable|url|starts_with:https://','backdrop_url'=>'nullable|url|starts_with:https://','hls_url'=>['required','url','starts_with:https://','regex:/\.m3u8(?:\?.*)?$/i'],'genre'=>'nullable|string|max:100','release_year'=>'nullable|integer|between:1900,2100','published'=>'boolean']);return response()->json(Movie::create($v),201);});
   Route::put('/movies/{movie}',function(Request $r,Movie $movie){$v=$r->validate(['title'=>'sometimes|required|string|max:255','slug'=>['sometimes','required','alpha_dash',Rule::unique('movies','slug')->ignore($movie->id)],'description'=>'nullable|string','poster_url'=>'nullable|url|starts_with:https://','backdrop_url'=>'nullable|url|starts_with:https://','hls_url'=>['sometimes','required','url','starts_with:https://','regex:/\.m3u8(?:\?.*)?$/i'],'genre'=>'nullable|string|max:100','release_year'=>'nullable|integer|between:1900,2100','published'=>'boolean']);$movie->update($v);return $movie;});
   Route::delete('/movies/{movie}',function(Movie $movie){$movie->delete();return response()->noContent();});
   Route::post('/logout',function(Request $r){$r->user()->currentAccessToken()->delete();return ['ok'=>true];});
  });
 });
});
