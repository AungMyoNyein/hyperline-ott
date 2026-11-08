<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void {Schema::table('users',function(Blueprint $t){$t->boolean('is_admin')->default(false);});Schema::create('movies',function(Blueprint $t){$t->id();$t->string('title');$t->string('slug')->unique();$t->text('description')->nullable();$t->text('poster_url')->nullable();$t->text('backdrop_url')->nullable();$t->text('hls_url');$t->string('genre')->nullable();$t->smallInteger('release_year')->nullable();$t->boolean('published')->default(false)->index();$t->timestamps();});}public function down():void{Schema::dropIfExists('movies');Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('is_admin'));}};
