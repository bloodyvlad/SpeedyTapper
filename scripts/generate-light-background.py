#!/usr/bin/env python3
"""Generate the original Daylight Circuit background loop.

The loop is intentionally calmer and lighter than the retired adaptive tracks:
four bars at 80 BPM, a major-key pad, restrained mid-bass, half-time tonal
percussion, and no lead voice. It uses deterministic synthesis only.
"""

from pathlib import Path
import subprocess
import tempfile
import wave

import numpy as np


RATE = 48_000
BPM = 80
BEAT_SECONDS = 60 / BPM
BAR_SECONDS = BEAT_SECONDS * 4
LOOP_SECONDS = BAR_SECONDS * 4
LOOP_FRAMES = int(LOOP_SECONDS * RATE)
ROOT = Path(__file__).resolve().parents[1]
MASTER_PATH = ROOT / "assets/audio/background-masters/daylight-circuit.wav"
RUNTIME_PATH = ROOT / "assets/audio/background-daylight-circuit.m4a"


def hz(midi_note):
    return 440.0 * 2 ** ((midi_note - 69) / 12)


def raised_cosine(frames, attack_seconds, release_seconds):
    envelope = np.ones(frames, dtype=np.float64)
    attack = min(frames, max(1, int(round(attack_seconds * RATE))))
    release = min(frames, max(1, int(round(release_seconds * RATE))))
    envelope[:attack] *= np.sin(np.linspace(0, np.pi / 2, attack)) ** 2
    envelope[-release:] *= np.cos(np.linspace(0, np.pi / 2, release)) ** 2
    envelope[0] = 0
    envelope[-1] = 0
    return envelope


def warm_pad(notes, duration, phase_offset):
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    signal = np.zeros(frames, dtype=np.float64)
    for index, note in enumerate(notes):
        frequency = hz(note)
        detune = 1 + (index - 1.5) * 0.00032
        phase = phase_offset + index * 0.47
        fundamental = np.sin(2 * np.pi * frequency * detune * time + phase)
        triangle_hint = 0.13 * np.sin(2 * np.pi * frequency * 2 * time + phase * 1.31)
        signal += fundamental + triangle_hint
    signal /= len(notes)
    motion = 0.90 + 0.10 * np.sin(2 * np.pi * 0.10 * time + phase_offset)
    return signal * motion * raised_cosine(frames, 0.52, 0.78) * 0.18


def round_bass(note, duration, accent=1.0):
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    phase = 2 * np.pi * hz(note) * time
    signal = np.sin(phase) + 0.11 * np.sin(2 * phase + 0.22)
    envelope = raised_cosine(frames, 0.030, 0.26) * np.exp(-time / (duration * 0.70))
    return np.tanh(signal * 0.92) * envelope * 0.18 * accent


def soft_thump(duration=0.36):
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    frequency = 62 + 30 * np.exp(-time * 18)
    phase = 2 * np.pi * np.cumsum(frequency) / RATE
    signal = np.sin(phase) + 0.07 * np.sin(2 * phase + 0.25)
    envelope = raised_cosine(frames, 0.018, 0.11) * np.exp(-time * 8.4)
    return signal * envelope * 0.22


def soft_knock(note, duration=0.24):
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    phase = 2 * np.pi * hz(note) * time
    signal = np.sin(phase) + 0.16 * np.sin(1.5 * phase + 0.4)
    envelope = raised_cosine(frames, 0.014, 0.085) * np.exp(-time * 10.5)
    return signal * envelope * 0.105


def chord_bloom(notes, duration=0.58):
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    signal = np.zeros(frames, dtype=np.float64)
    for index, note in enumerate(notes):
        frequency = hz(note)
        phase = index * 0.63
        signal += np.sin(2 * np.pi * frequency * time + phase)
        signal += 0.06 * np.sin(2 * np.pi * frequency * 2 * time + phase * 1.2)
    signal /= len(notes)
    envelope = raised_cosine(frames, 0.075, 0.25) * np.exp(-time * 1.5)
    return signal * envelope * 0.075


def place(bus, sound, start_frame, gain=1.0, pan=0.0):
    if start_frame >= len(bus) or start_frame + len(sound) <= 0:
        return
    source_start = max(0, -start_frame)
    destination_start = max(0, start_frame)
    count = min(len(sound) - source_start, len(bus) - destination_start)
    if count <= 0:
        return
    segment = sound[source_start:source_start + count] * gain
    left = np.sqrt((1 - pan) * 0.5)
    right = np.sqrt((1 + pan) * 0.5)
    bus[destination_start:destination_start + count, 0] += segment * left
    bus[destination_start:destination_start + count, 1] += segment * right


def render_three_loops():
    total_frames = LOOP_FRAMES * 3
    bus = np.zeros((total_frames, 2), dtype=np.float64)

    # F#6/9, D#m7, Badd9, C#sus4 resolving gently back toward F#.
    chords = (
        (54, 58, 61, 68),
        (51, 54, 58, 61),
        (47, 51, 54, 61),
        (49, 54, 56, 59),
    )
    bass_notes = (42, 39, 35, 37)
    knock_notes = (73, 70, 66, 68)
    bar_frames = int(round(BAR_SECONDS * RATE))
    beat_frames = int(round(BEAT_SECONDS * RATE))

    for repetition in range(3):
        loop_start = repetition * LOOP_FRAMES
        for bar_index, chord in enumerate(chords):
            bar_start = loop_start + bar_index * bar_frames
            pad_sound = warm_pad(chord, BAR_SECONDS + 1.08, bar_index * 0.41)
            place(bus, pad_sound, bar_start - int(0.38 * RATE), gain=0.92,
                  pan=-0.10 if bar_index % 2 else 0.10)

            for beat_index in (0, 2):
                beat_start = bar_start + beat_index * beat_frames
                place(bus, round_bass(bass_notes[bar_index], 0.92,
                                      1.0 if beat_index == 0 else 0.82), beat_start,
                      gain=0.82)
                place(bus, soft_thump(), beat_start, gain=0.52)

            for beat_index in (1, 3):
                beat_start = bar_start + beat_index * beat_frames
                place(bus, soft_knock(knock_notes[bar_index]), beat_start,
                      gain=0.70, pan=-0.22 if beat_index == 1 else 0.22)

            # A quiet offbeat chord pulse adds lift without becoming a melody.
            for beat_index in (0, 2):
                pulse_start = bar_start + beat_index * beat_frames + beat_frames // 2
                place(bus, chord_bloom(tuple(note + 12 for note in chord)), pulse_start,
                      gain=0.60, pan=0.15 if beat_index == 0 else -0.15)

    dry = bus.copy()
    for delay_seconds, gain, destination, source in (
        (0.083, 0.055, 0, 1),
        (0.127, 0.048, 1, 0),
        (0.241, 0.024, 0, 1),
        (0.287, 0.022, 1, 0),
    ):
        shift = int(round(delay_seconds * RATE))
        bus[shift:, destination] += dry[:-shift, source] * gain
    return np.tanh(bus * 1.04) / np.tanh(1.04)


def write_pcm32(path, audio):
    pcm = np.int32(np.clip(audio, -1, 1) * 2_147_483_647)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(2)
        output.setsampwidth(4)
        output.setframerate(RATE)
        output.writeframes(pcm.astype("<i4").tobytes())


def read_pcm16(path):
    with wave.open(str(path), "rb") as source:
        frames = source.readframes(source.getnframes())
    return np.frombuffer(frames, dtype="<i2").reshape(-1, 2).copy()


def write_pcm16(path, audio):
    with wave.open(str(path), "wb") as output:
        output.setnchannels(2)
        output.setsampwidth(2)
        output.setframerate(RATE)
        output.writeframes(audio.astype("<i2").tobytes())


def generate():
    MASTER_PATH.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="speedytapper-daylight-") as temporary:
        temporary_path = Path(temporary)
        raw_path = temporary_path / "three-loops-raw.wav"
        mastered_path = temporary_path / "three-loops-mastered.wav"
        write_pcm32(raw_path, render_three_loops())
        subprocess.run(
            [
                "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                "-i", str(raw_path),
                "-af",
                (
                    "highpass=f=42,lowpass=f=7200,"
                    "bass=g=-2.5:f=95:w=0.65,treble=g=1.0:f=3200:w=0.45,"
                    "acompressor=threshold=0.18:ratio=1.7:attack=28:release=230:makeup=1.06,"
                    "loudnorm=I=-18.2:LRA=4:TP=-4.0"
                ),
                "-ar", str(RATE), "-c:a", "pcm_s16le", str(mastered_path),
            ],
            check=True,
        )
        mastered = read_pcm16(mastered_path)
        start = LOOP_FRAMES
        loop = mastered[start:start + LOOP_FRAMES]
        if len(loop) != LOOP_FRAMES:
            raise RuntimeError("The mastered loop does not contain the expected frame count.")
        write_pcm16(MASTER_PATH, loop)

    subprocess.run(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-i", str(MASTER_PATH), "-map_metadata", "-1",
            "-metadata", "title=Daylight Circuit Background",
            "-metadata", "artist=SpeedyTapper",
            "-metadata", "comment=Original calm background loop",
            "-c:a", "aac", "-b:a", "160k", "-ar", str(RATE),
            "-movflags", "+faststart", str(RUNTIME_PATH),
        ],
        check=True,
    )


if __name__ == "__main__":
    generate()
