<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HomepageController extends Controller
{
    public const SECTIONS = ['continue', 'trending', 'latest', 'genres', 'series', 'vertical', 'featured'];

    public function show()
    {
        return response()->json(['data' => ['sections' => json_decode(DB::table('homepage_settings')->where('id', 1)->value('sections') ?? 'null', true) ?? self::SECTIONS]]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'sections' => ['required', 'array', 'size:7'],
            'sections.*' => ['required', 'string', 'distinct', Rule::in(self::SECTIONS)],
        ]);
        DB::transaction(function () use ($request, $data) {
            DB::table('homepage_settings')->updateOrInsert(['id' => 1], ['sections' => json_encode($data['sections'])]);
            AdminAuditLog::record($request->user(), 'homepage.updated', null, 'Updated homepage section order', $data);
        });

        return $this->show();
    }
}
