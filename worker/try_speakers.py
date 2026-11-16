"""Print who speaks each line of a video: python try_speakers.py <video>

Without a video it makes a sample of three English voices taking turns (needs internet for edge-tts).
Run it in the worker container: docker compose run --rm worker python try_speakers.py [video]
"""

import subprocess
import sys
import tempfile
import time
from pathlib import Path

import edge_tts

from stages.audio import extract_audio
from stages.speakers import find_speakers
from stages.transcribe import transcribe

TURNS = [
    ("en-US-AriaNeural", "Good morning everyone, and thank you for coming to the meeting today."),
    ("en-US-GuyNeural", "Thanks Aria. I would like to start with the sales numbers from last month."),
    ("en-GB-SoniaNeural", "Before we begin, can somebody share the slides on the big screen?"),
    ("en-US-GuyNeural", "Of course, give me one moment while I open the presentation."),
    ("en-US-AriaNeural", "Perfect. While we wait, let us look at the agenda for this afternoon."),
    ("en-GB-SoniaNeural", "I have also added a short point about the new office in Phnom Penh."),
]


def sample(folder: Path) -> Path:
    """Write a video of the TURNS with 0.8 s between them and return its path."""
    parts = []
    for n, (voice, text) in enumerate(TURNS):
        mp3 = folder / f"{n}.mp3"
        edge_tts.Communicate(text, voice).save_sync(str(mp3))
        parts.append(mp3)
    inputs = [arg for mp3 in parts for arg in ("-i", str(mp3))]
    chain = "".join(f"[{n}:a]apad=pad_dur=0.8[a{n}];" for n in range(len(parts)))
    chain += "".join(f"[a{n}]" for n in range(len(parts))) + f"concat=n={len(parts)}:v=0:a=1[out]"
    video = folder / "speakers.mp4"
    subprocess.run(["ffmpeg", "-nostdin", "-loglevel", "error", "-y", *inputs, "-f", "lavfi", "-i",
                    "color=c=black:size=160x120:rate=10", "-filter_complex", chain, "-map", f"{len(parts)}:v",
                    "-map", "[out]", "-shortest", "-c:v", "mpeg4", "-c:a", "aac", str(video)], check=True)
    return video


def main() -> None:
    folder = Path(tempfile.mkdtemp())
    video = Path(sys.argv[1]) if len(sys.argv) > 1 else sample(folder)
    wav = folder / "audio.wav"
    extract_audio(video, wav)
    segments, language = transcribe(wav, language="en" if len(sys.argv) == 1 else None)
    began = time.monotonic()
    speakers = find_speakers(wav, segments)
    print(f"{len(segments)} lines, {len(speakers)} speakers in {time.monotonic() - began:.1f} s")
    for spk in speakers:
        print(f"  speaker {spk['id'] + 1}: {spk['gender']}, {spk['lines']} lines")
    for seg in segments:
        print(f"[{seg['start']:6.2f}-{seg['end']:6.2f}] speaker {seg['speaker'] + 1}: {seg['text']}")


if __name__ == "__main__":
    main()
