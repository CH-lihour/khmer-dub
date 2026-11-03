@php
    // Khmer for every step message and status word the page shows. Machine-made: a Khmer speaker should review it.
    $km = [
        'Uploading' => 'កំពុងផ្ទុកឡើង',
        'Waiting in the queue' => 'កំពុងរង់ចាំក្នុងជួរ',
        'Sending the video to the worker' => 'កំពុងផ្ញើវីដេអូ',
        'Starting' => 'កំពុងចាប់ផ្តើម',
        'Extracting audio' => 'កំពុងទាញយកសំឡេង',
        'Transcribing speech' => 'កំពុងសរសេរសំដីជាអក្សរ',
        'Translating to Khmer' => 'កំពុងបកប្រែជាភាសាខ្មែរ',
        'Making the Khmer voice' => 'កំពុងបង្កើតសំឡេងខ្មែរ',
        'Mixing and merging' => 'កំពុងបញ្ចូលសំឡេងទៅក្នុងវីដេអូ',
        'Downloading the results' => 'កំពុងរៀបចំលទ្ធផល',
        'Done' => 'រួចរាល់',
        'Failed' => 'បរាជ័យ',
        'queued' => 'រង់ចាំ',
        'processing' => 'កំពុងដំណើរការ',
        'done' => 'រួចរាល់',
        'failed' => 'បរាជ័យ',
    ];
    $statusLabel = ['queued' => 'Queued', 'processing' => 'Processing', 'done' => 'Done', 'failed' => 'Failed'];
@endphp
<x-app-layout>
    <h1>Dub a video <span class="km">· បញ្ចូលសំឡេងវីដេអូ</span></h1>
    <p class="lead">
        Upload a video in any language and get Khmer subtitles and a Khmer voice track.<br>
        <span lang="km">ផ្ទុកវីដេអូជាភាសាណាមួយ ហើយទទួលបានអក្សររត់ និងសំឡេងជាភាសាខ្មែរ។</span>
    </p>

    <form id="upload" class="card" method="post" action="{{ route('dub.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
            <label for="video">Video file <span class="km">· ឯកសារវីដេអូ</span></label>
            <input id="video" name="video" type="file" required
                   accept=".mp4,.mov,.mkv,.webm,video/mp4,video/quicktime,video/x-matroska,video/webm">
            <span class="hint">MP4, MOV, MKV or WebM, up to 500 MB and 15 minutes · រហូតដល់ 500 MB និង 15 នាទី</span>
        </div>
        <div class="field">
            <label for="gender">Voice <span class="km">· សំឡេង</span></label>
            <select id="gender" name="gender">
                <option value="female" @selected(old('gender', 'female') === 'female')>Female (Sreymom) · ស្រី</option>
                <option value="male" @selected(old('gender') === 'male')>Male (Piseth) · ប្រុស</option>
            </select>
        </div>
        <div class="field">
            <label class="check">
                <input type="checkbox" name="dub" value="1" @checked(! $errors->any() || old('dub'))>
                <span>Make a dubbed video <span class="km">· បង្កើតវីដេអូបញ្ចូលសំឡេងខ្មែរ</span>
                    <span class="hint">Unchecked: Khmer subtitles only · បើមិនធីក៖ បានតែអក្សររត់ខ្មែរ</span></span>
            </label>
        </div>
        <button id="start" type="submit">Start · ចាប់ផ្តើម</button>
        <ul id="errors" class="errors">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </form>

    <section id="job" class="card" @if (! $current) hidden @endif aria-live="polite">
        <div class="row">
            <h2 id="job-name" class="name">{{ $current['name'] ?? '' }}</h2>
            <strong id="job-percent">{{ isset($current) ? $current['progress'].'%' : '' }}</strong>
        </div>
        <div id="job-bar" class="bar {{ $current['status'] ?? '' }}" role="progressbar" aria-label="Progress"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $current['progress'] ?? 0 }}">
            <span style="width: {{ $current['progress'] ?? 0 }}%"></span>
        </div>
        <p id="job-message">{{ $current['message'] ?? '' }}</p>
        <p id="job-error" class="error" @if (empty($current['error'])) hidden @endif>{{ $current['error'] ?? '' }}</p>
        <p id="job-language" class="hint" @if (empty($current['language_name'])) hidden @endif>
            Spoken language · ភាសាដើម: <span>{{ $current['language_name'] ?? '' }}</span>
        </p>
        <div class="links">
            <a id="job-srt" href="{{ $current['srt_url'] ?? '#' }}" @if (empty($current['srt_url'])) hidden @endif>
                Download subtitles (.srt) · ទាញយកអក្សររត់</a>
            <a id="job-video" href="{{ $current['video_url'] ?? '#' }}" @if (empty($current['video_url'])) hidden @endif>
                Download dubbed video (.mp4) · ទាញយកវីដេអូ</a>
        </div>
    </section>

    <section class="card">
        <h2>Your videos <span class="km">· វីដេអូរបស់អ្នក</span></h2>
        <table>
            <thead><tr><th>Video</th><th class="when">Started</th><th>Status</th><th>Results</th></tr></thead>
            <tbody id="history">
            @forelse ($jobs as $job)
                <tr data-id="{{ $job->id }}">
                    <td class="name"><a href="{{ route('dub.index', ['job' => $job->id]) }}">{{ $job->original_name }}</a></td>
                    <td class="when">{{ $job->created_at?->diffForHumans() }}</td>
                    <td class="status s-{{ $job->status }}">{{ $statusLabel[$job->status] ?? $job->status }} · {{ $km[$job->status] ?? '' }}</td>
                    <td class="results">
                        @if ($job->srt_path)<a href="{{ route('dub.download', [$job, 'srt']) }}">SRT</a>@endif
                        @if ($job->video_path)<a href="{{ route('dub.download', [$job, 'video']) }}">MP4</a>@endif
                    </td>
                </tr>
            @empty
                <tr id="history-empty"><td colspan="4">No videos yet · មិនទាន់មានវីដេអូនៅឡើយ</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="hint">Videos and their results are deleted after 7 days · វីដេអូ និងលទ្ធផលត្រូវលុបចោលក្រោយ 7 ថ្ងៃ</p>
    </section>

    <footer>
        <p>The translation and the voice are made by free AI models and need review by a Khmer speaker before use.
            Upload only videos you own or may translate, and label dubbed videos as having an AI-generated voice.</p>
        <p lang="km">ការបកប្រែ និងសំឡេង បង្កើតដោយ AI ឥតគិតថ្លៃ ហើយត្រូវការអ្នកនិយាយភាសាខ្មែរពិនិត្យមុនពេលប្រើ។
            សូមផ្ទុកតែវីដេអូដែលអ្នកជាម្ចាស់ ឬមានសិទ្ធិបកប្រែ ហើយបញ្ជាក់ថាវីដេអូនេះប្រើសំឡេង AI។</p>
    </footer>

    <x-slot name="scripts">
    <script>
    (() => {
        const KM = @json($km);
        const STATUS = @json($statusLabel);
        const POLL_MS = 3000;
        const SIGNED_OUT = 'You are signed out. Reload the page and log in again. · សូមចូលគណនីម្តងទៀត';
        const $ = (id) => document.getElementById(id);
        const form = $('upload'), start = $('start'), errors = $('errors');
        let timer = null;

        /** "English · Khmer" for a known step message, or the message as it came. */
        const bilingual = (text) => (KM[text] ? `${text} · ${KM[text]}` : text || '');

        function showErrors(list) {
            errors.replaceChildren(...list.map((text) => Object.assign(document.createElement('li'), { textContent: text })));
        }

        /** Draw a job (the JSON from the status route) in the progress card and in its history row. */
        function render(job) {
            $('job').hidden = false;
            $('job-name').textContent = job.name;
            $('job-percent').textContent = `${job.progress}%`;
            const bar = $('job-bar');
            bar.className = `bar ${job.status}`;
            bar.setAttribute('aria-valuenow', job.progress);
            bar.firstElementChild.style.width = `${job.progress}%`;
            $('job-message').textContent = bilingual(job.message);
            $('job-error').hidden = !job.error;
            $('job-error').textContent = job.error || '';
            $('job-language').hidden = !job.language_name;
            $('job-language').querySelector('span').textContent = job.language_name || '';
            for (const [id, url] of [['job-srt', job.srt_url], ['job-video', job.video_url]]) {
                $(id).hidden = !url;
                $(id).href = url || '#';
            }
            if (job.id) renderRow(job);
        }

        function renderRow(job) {
            let row = document.querySelector(`#history tr[data-id="${job.id}"]`);
            if (!row) {
                $('history-empty')?.remove();
                row = document.createElement('tr');
                row.dataset.id = job.id;
                row.innerHTML = '<td class="name"><a></a></td><td class="when">just now</td><td class="status"></td><td class="results"></td>';
                row.querySelector('a').textContent = job.name;
                row.querySelector('a').href = `?job=${job.id}`;
                $('history').prepend(row);
            }
            const status = row.querySelector('.status');
            status.className = `status s-${job.status}`;
            status.textContent = `${STATUS[job.status] || job.status} · ${KM[job.status] || ''}`;
            const links = [[job.srt_url, 'SRT'], [job.video_url, 'MP4']].filter(([url]) => url)
                .map(([url, text]) => Object.assign(document.createElement('a'), { href: url, textContent: text }));
            row.querySelector('.results').replaceChildren(...links.flatMap((a) => [a, ' ']));
        }

        /** Ask for the job's status every 3 seconds until it is done or failed. */
        async function poll(url) {
            clearTimeout(timer);
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (response.status === 401 || response.status === 403) {
                    $('job-error').hidden = false;
                    $('job-error').textContent = response.status === 401 ? SIGNED_OUT : 'This video belongs to another account.';
                    return;
                }
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const job = await response.json();
                render(job);
                if (job.status === 'done' || job.status === 'failed') return;
            } catch (error) {
                $('job-message').textContent = 'Lost contact with the site, trying again… · កំពុងព្យាយាមម្តងទៀត';
            }
            timer = setTimeout(() => poll(url), POLL_MS);
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const file = $('video').files[0];
            if (!file) return showErrors(['Choose a video file.']);
            showErrors([]);
            clearTimeout(timer);
            start.disabled = true;
            render({ name: file.name, status: 'queued', progress: 0, message: 'Uploading', error: null });
            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
                if (response.status === 201) {
                    const { id, status_url: url } = await response.json();
                    history.replaceState(null, '', `?job=${id}`);
                    form.reset();
                    poll(url);
                    return;
                }
                $('job').hidden = true;
                if (response.status === 422) {
                    const body = await response.json();
                    showErrors(Object.values(body.errors || {}).flat());
                } else if (response.status === 413) {
                    showErrors(['The video is larger than 500 MB.']);
                } else if (response.status === 401) {
                    showErrors([SIGNED_OUT]);
                } else if (response.status === 419) {
                    showErrors(['The page has expired. Reload it and try again.']);
                } else {
                    showErrors([`The upload failed (HTTP ${response.status}). Please try again.`]);
                }
            } catch (error) {
                $('job').hidden = true;
                showErrors(['The site could not be reached. Check that it is running and try again.']);
            } finally {
                start.disabled = false;
            }
        });

        const current = @json($current);
        if (current) {
            render(current);
            if (current.status !== 'done' && current.status !== 'failed') poll(current.status_url);
        }
    })();
    </script>
    </x-slot>
</x-app-layout>
