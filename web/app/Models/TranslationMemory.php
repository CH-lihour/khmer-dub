<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Khmer a person wrote for one line when reviewing a video. The worker reuses it when the same
 * line comes up again in a video in the same spoken language.
 *
 * @property int $id
 * @property int $user_id
 * @property string $language
 * @property string $source
 * @property string $source_key
 * @property string $km
 */
class TranslationMemory extends Model
{
    /** The newest lines sent with each video; the worker takes at most 5000. */
    public const SENT = 2000;

    /** @var list<string> */
    protected $fillable = ['user_id', 'language', 'source', 'source_key', 'km'];

    /**
     * The key a line is stored under: case and extra spaces do not count. The worker compares
     * lines the same way, and also ignores the final punctuation.
     */
    public static function keyOf(string $source): string
    {
        return sha1(preg_replace('/\s+/u', ' ', mb_strtolower(trim($source))));
    }

    /**
     * Remember the Khmer for each line a person changed. `$lines` is [[source, old Khmer, new Khmer]].
     * A line emptied, or left as the machine wrote it, is not remembered.
     *
     * @param  iterable<array{0: string, 1: string, 2: string}>  $lines
     */
    public static function remember(User $user, string $language, iterable $lines): int
    {
        $count = 0;
        foreach ($lines as [$source, $old, $new]) {
            $new = trim($new);
            if ($new === '' || trim($source) === '' || $new === trim($old)) {
                continue;
            }
            self::updateOrCreate(
                ['user_id' => $user->id, 'language' => $language, 'source_key' => self::keyOf($source)],
                ['source' => mb_substr(trim($source), 0, 2000), 'km' => mb_substr($new, 0, 2000)],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
