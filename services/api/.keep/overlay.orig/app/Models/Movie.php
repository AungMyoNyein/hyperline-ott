<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Movie extends Model { protected $fillable=['title','slug','description','poster_url','backdrop_url','hls_url','genre','release_year','published']; protected function casts():array{return ['published'=>'boolean','release_year'=>'integer'];} protected $hidden=['hls_url']; }
