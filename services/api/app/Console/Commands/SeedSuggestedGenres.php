<?php

namespace App\Console\Commands;

use App\Models\Genre;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedSuggestedGenres extends Command
{
    protected $signature = 'ott:seed-suggested-genres';

    protected $description = 'Add missing suggested genres in local/testing environments only';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Suggested genres may only be seeded in an isolated local/testing environment.');

            return self::FAILURE;
        }
        $names = [
            'Action' => 'အက်ရှင်', 'Comedy' => 'ဟာသ', 'Romance' => 'အချစ်',
            'Drama' => 'ဒရာမာ', 'Horror' => 'သရဲ', 'Thriller' => 'ရင်ဖို',
            'Family' => 'မိသားစု', 'Adventure' => 'စွန့်စားခန်း', 'Fantasy' => 'စိတ်ကူးယဉ်',
            'Historical' => 'သမိုင်း', 'Mystery' => 'လျှို့ဝှက်ဆန်းကြယ်', 'Documentary' => 'မှတ်တမ်း',
        ];
        foreach ($names as $name => $myanmar) {
            Genre::firstOrCreate(['slug' => Str::slug($name)], [
                'name' => $name, 'name_my' => $myanmar, 'sort_order' => array_search($name, array_keys($names)) * 10,
            ]);
        }
        $this->info('Missing suggestions added; existing labels and content assignments preserved.');

        return self::SUCCESS;
    }
}
