<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The user's glossary, sent with every video so names and terms come out as they want, and the
 * memory of their review edits.
 */
class GlossaryController extends Controller
{
    /** The worker takes at most this many terms. */
    public const MAX_TERMS = 500;

    /**
     * Show the glossary, one term a line as "term = Khmer", and how many edits are remembered.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();
        $text = collect($user->glossaryEntries())
            ->map(fn (array $entry) => $entry['km'] === '' ? $entry['term'] : "{$entry['term']} = {$entry['km']}")
            ->implode("\n");

        return view('glossary.edit', ['text' => $text, 'remembered' => $user->translationMemories()->count()]);
    }

    /**
     * Save the glossary from its text: one term a line, "term = Khmer" to put that Khmer, or the
     * term alone to keep it as written. Empty lines are skipped; a term given twice keeps the last.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate(['glossary' => ['nullable', 'string', 'max:100000']]);
        $entries = [];
        $lines = preg_split('/\R/u', (string) $request->input('glossary'));
        foreach ($lines as $number => $line) {
            [$term, $km] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            $term = preg_replace('/\s+/u', ' ', $term);
            if ($term === '') {
                if ($km !== '') {
                    $this->refuse(__('Line :number has Khmer but no term before the =.', ['number' => $number + 1]));
                }

                continue;
            }
            if (mb_strlen($term) > 100 || mb_strlen($km) > 200) {
                $this->refuse(__('Line :number is too long: a term can have 100 characters and its Khmer 200.', ['number' => $number + 1]));
            }
            $entries[mb_strtolower($term)] = ['term' => $term, 'km' => $km];
        }
        if (count($entries) > self::MAX_TERMS) {
            $this->refuse(__('The glossary can have at most :max terms.', ['max' => self::MAX_TERMS]));
        }

        $request->user()->forceFill(['glossary' => array_values($entries)])->save();

        return redirect()->route('glossary.edit')->with('notice', __('Saved. It is used for the next videos you upload.'));
    }

    /**
     * Forget every line the user corrected, so later videos are translated afresh.
     */
    public function forget(Request $request): RedirectResponse
    {
        $request->user()->translationMemories()->delete();

        return redirect()->route('glossary.edit')->with('notice', __('Your remembered edits are forgotten.'));
    }

    /**
     * Send the user back to the form with the text they typed and why it was refused.
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['glossary' => $message]);
    }
}
