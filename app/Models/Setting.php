<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings';

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'key',
        'value',
    ];

    /** Request attribute holding the settings already read during this request. */
    private const MEMO = 'kisauni.settings.memo';

    /**
     * Settings are read on every page (school name, year, logo - for every view that is drawn),
     * so they are loaded once per request with ONE query instead of one query per read.
     */
    public static function get(string $key, $default = null)
    {
        if (!request()->route()) {
            // not inside a web request (console, seeders): always read fresh
            $setting = static::find($key);
            return $setting ? $setting->value : $default;
        }
        $memo = request()->attributes; // lives only as long as this one request
        if (!$memo->has(self::MEMO)) {
            $memo->set(self::MEMO, static::query()->pluck('value', 'key')->all());
        }
        $all = $memo->get(self::MEMO);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);

        $memo = request()->attributes;
        if ($memo->has(self::MEMO)) {
            $all = $memo->get(self::MEMO);
            $all[$key] = $value;
            $memo->set(self::MEMO, $all);
        }
    }
}
