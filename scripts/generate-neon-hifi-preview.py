#!/usr/bin/env python3
"""Render the warm, high-fidelity Neon Circuit adaptive-music preview."""

from pathlib import Path
import subprocess
import wave

import numpy as np


RATE = 48_000
ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets" / "audio" / "music-previews"


def hz(midi_note):
    return 440.0 * 2 ** ((midi_note - 69) / 12)


def stereo(duration):
    return np.zeros((int(round(duration * RATE)), 2), dtype=np.float64)


def place(bus, sound, when, gain=1.0, pan=0.0):
    start = int(round(when * RATE))
    if start >= len(bus):
        return
    sound = sound[: len(bus) - start]
    if sound.ndim == 1:
        left = np.sqrt((1 - pan) * 0.5)
        right = np.sqrt((1 + pan) * 0.5)
        sound = np.column_stack((sound * left, sound * right))
    bus[start:start + len(sound)] += sound * gain


def soft_clip(signal, drive=1.0):
    return np.tanh(signal * drive) / np.tanh(drive)


def fade_edges(signal, attack=0.004, release=0.025):
    result = signal.copy()
    attack_count = min(len(result), max(1, int(attack * RATE)))
    release_count = min(len(result), max(1, int(release * RATE)))
    attack_curve = np.sin(np.linspace(0, np.pi / 2, attack_count)) ** 2
    release_curve = np.cos(np.linspace(0, np.pi / 2, release_count)) ** 2
    result[:attack_count] *= attack_curve
    result[-release_count:] *= release_curve
    return result


def kick():
    duration = 0.55
    t = np.arange(int(duration * RATE)) / RATE
    frequency = 44 + 78 * np.exp(-t * 28)
    phase = 2 * np.pi * np.cumsum(frequency) / RATE
    body = np.sin(phase) * np.exp(-t * 8.4)
    sub = np.sin(phase * 0.5) * np.exp(-t * 12) * 0.16
    return fade_edges(soft_clip(body + sub, 1.25) * 0.82, attack=0.003, release=0.035)


def low_tom(frequency=145):
    duration = 0.32
    t = np.arange(int(duration * RATE)) / RATE
    phase = 2 * np.pi * (frequency * t + 15 * (1 - np.exp(-t * 25)) / 25)
    return fade_edges(np.sin(phase) * np.exp(-t * 15) * 0.28, release=0.035)


def bass(note, duration, accent=1.0):
    t = np.arange(int(duration * RATE)) / RATE
    f = hz(note)
    phase = 2 * np.pi * f * t
    tone = (
        np.sin(phase)
        + 0.26 * np.sin(2 * phase + 0.15)
        + 0.08 * np.sin(3 * phase + 0.4)
        + 0.18 * np.sin(phase * 0.5)
    )
    attack = np.minimum(1, t / 0.012)
    release = np.exp(-t / max(0.12, duration * 0.72))
    return fade_edges(
        soft_clip(tone * attack * release, 1.15) * 0.30 * accent,
        attack=0.003,
        release=min(0.025, duration * 0.2),
    )


def velvet_pluck(note, duration, accent=1.0):
    t = np.arange(int(duration * RATE)) / RATE
    f = hz(note)
    carrier = np.sin(2 * np.pi * f * t + 0.65 * np.sin(2 * np.pi * f * 2 * t) * np.exp(-t * 7))
    warmth = 0.20 * np.sin(2 * np.pi * f * 0.5 * t)
    envelope = np.minimum(1, t / 0.009) * np.exp(-t / max(0.08, duration * 0.38))
    return fade_edges(
        (carrier + warmth) * envelope * 0.19 * accent,
        attack=0.004,
        release=min(0.03, duration * 0.24),
    )


def pad(notes, duration, motion=0.0):
    t = np.arange(int(duration * RATE)) / RATE
    sound = np.zeros_like(t)
    for index, note in enumerate(notes):
        f = hz(note)
        sound += np.sin(2 * np.pi * f * (1 + (index - 1.5) * 0.0008) * t + index * 0.4)
        sound += 0.16 * np.sin(2 * np.pi * f * 2 * t + index)
    sound /= len(notes)
    fade_in = np.minimum(1, t / 0.85)
    fade_out = np.minimum(1, np.maximum(0, duration - t) / 0.9)
    movement = 0.82 + 0.18 * np.sin(2 * np.pi * (0.10 + motion) * t)
    return sound * fade_in * fade_out * movement * 0.13


CHORDS = (
    (50, 53, 57, 60),  # Dm7
    (46, 50, 53, 57),  # Bbmaj7
    (53, 57, 60, 64),  # Fmaj7
    (48, 50, 55, 62),  # Csus2/add9
)
BASS_PATTERN = (38, 38, 45, 41, 38, 48, 45, 41)
PLUCK_SCALE = (50, 53, 57, 60, 62, 60, 57, 53)


def render_section(bpm, stage, bars=4):
    beat = 60 / bpm
    bar_duration = beat * 4
    duration = bars * bar_duration
    music = stereo(duration)
    drums = stereo(duration)

    for bar_index in range(bars):
        bar_start = bar_index * bar_duration
        chord = CHORDS[bar_index % len(CHORDS)]
        place(music, pad(chord, bar_duration, stage * 0.015), bar_start,
              gain=0.92, pan=(-0.08 if bar_index % 2 else 0.08))
        if stage >= 2:
            place(music, pad(tuple(note - 12 for note in chord), bar_duration, 0.01),
                  bar_start, gain=0.28 + stage * 0.03, pan=(0.12 if bar_index % 2 else -0.12))

        kick_beats = (0, 2) if stage == 0 else (0, 1, 2, 3)
        for beat_index in kick_beats:
            place(drums, kick(), bar_start + beat_index * beat, gain=0.92 if stage else 0.72)

        if stage >= 1:
            for beat_index in (1, 3):
                place(drums, low_tom(190), bar_start + beat_index * beat, gain=0.23, pan=0.08)

        if stage >= 2:
            place(drums, low_tom(145), bar_start + 1.75 * beat, gain=0.62, pan=-0.28)
            place(drums, low_tom(118), bar_start + 3.5 * beat, gain=0.68, pan=0.28)

        steps = 8
        step_duration = bar_duration / steps
        for step_index in range(steps):
            when = bar_start + step_index * step_duration
            pattern_index = (step_index * 8 // steps + bar_index * 2) % len(BASS_PATTERN)
            if stage == 0:
                active = step_index in (0, 4)
            elif stage == 1:
                active = step_index % 2 == 0 or step_index in (3, 7)
            else:
                active = step_index % (2 if stage == 2 else 1) == 0
            if active:
                place(music, bass(BASS_PATTERN[pattern_index], step_duration * 1.25,
                                  1.12 if step_index == 0 else 0.92), when, gain=0.88)

            pluck_steps = (0, 4) if stage == 1 else (0, 3, 6) if stage == 2 else (0, 3, 5)
            if stage >= 1 and step_index in pluck_steps:
                melodic_index = (step_index * 8 // steps + bar_index) % len(PLUCK_SCALE)
                note = PLUCK_SCALE[melodic_index] + (0 if stage < 3 else 12 if step_index % 4 == 0 else 0)
                place(music, velvet_pluck(note, step_duration * 1.8,
                                          1.1 if step_index % 4 == 0 else 0.78),
                      when, gain=0.72 if stage == 1 else 0.82,
                      pan=(-0.30 if step_index % 2 else 0.30))

    # Gentle musical-bus ducking gives the kick space without pumping aggressively.
    duck = np.ones(len(music))
    for bar_index in range(bars):
        bar_start = bar_index * bar_duration
        for beat_index in ((0, 2) if stage == 0 else (0, 1, 2, 3)):
            start = int((bar_start + beat_index * beat) * RATE)
            count = min(int(0.22 * RATE), len(duck) - start)
            if count > 0:
                t = np.arange(count) / RATE
                duck[start:start + count] *= 1 - 0.28 * np.exp(-t * 14)
    music *= duck[:, None]

    mix = music + drums
    # Short, dark stereo ambience rather than a bright algorithmic reverb.
    dry = mix.copy()
    for delay, gain, channel in ((0.105, 0.10, 0), (0.137, 0.09, 1), (0.231, 0.045, 0), (0.277, 0.04, 1)):
        shift = int(delay * RATE)
        mix[shift:, channel] += dry[:-shift, 1 - channel] * gain
    edge_count = min(len(mix), int(0.025 * RATE))
    mix[:edge_count] *= np.sin(np.linspace(0, np.pi / 2, edge_count))[:, None] ** 2
    mix[-edge_count:] *= np.cos(np.linspace(0, np.pi / 2, edge_count))[:, None] ** 2
    return mix


def write_pcm32(path, audio):
    peak = np.max(np.abs(audio))
    if peak > 0:
        audio = audio / max(1.0, peak / 0.94)
    audio = soft_clip(audio, 1.08) * 0.92
    pcm = np.int32(np.clip(audio, -1, 1) * 2_147_483_647)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(2)
        output.setsampwidth(4)
        output.setframerate(RATE)
        output.writeframes(pcm.tobytes())


def read_pcm16(path):
    with wave.open(str(path), "rb") as source:
        if (
            source.getnchannels() != 2
            or source.getsampwidth() != 2
            or source.getframerate() != RATE
        ):
            raise ValueError(f"Unexpected PCM format in {path}")
        frames = source.readframes(source.getnframes())
    return np.frombuffer(frames, dtype="<i2").reshape(-1, 2).astype(np.float64) / 32_768


def write_pcm16(path, audio):
    pcm = np.int16(np.clip(audio, -1, 1) * 32_767)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(2)
        output.setsampwidth(2)
        output.setframerate(RATE)
        output.writeframes(pcm.tobytes())


def finalize_loop_edges(audio, duration=0.06):
    """Apply the last edge treatment after mastering so loop points stay silent."""
    result = audio.copy()
    count = min(len(result) // 2, max(1, int(duration * RATE)))
    fade_in = np.sin(np.linspace(0, np.pi / 2, count)) ** 2
    fade_out = np.cos(np.linspace(0, np.pi / 2, count)) ** 2
    result[:count] *= fade_in[:, None]
    result[-count:] *= fade_out[:, None]
    result[0] = 0
    result[-1] = 0
    return result


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    sections = (
        render_section(72, 0),
        render_section(88, 1),
        render_section(96, 2),
        render_section(168, 3),
    )
    mastered_sections = []
    temporary_paths = []
    for index, section in enumerate(sections):
        source_path = OUT / f"01-neon-circuit-clicksafe-v4.section-{index}.source.wav"
        processed_path = OUT / f"01-neon-circuit-clicksafe-v4.section-{index}.wav"
        temporary_paths.extend((source_path, processed_path))
        write_pcm32(source_path, section)
        subprocess.run(
            [
                "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                "-i", str(source_path),
                "-af",
                (
                    "highpass=f=25,lowpass=f=10000,highshelf=f=4500:g=-4,"
                    "acompressor=threshold=0.22:ratio=2.2:attack=12:release=140:makeup=1.15,"
                    "volume=2.3dB,alimiter=limit=0.94"
                ),
                "-ar", str(RATE), "-c:a", "pcm_s16le", str(processed_path),
            ],
            check=True,
        )
        mastered_sections.append(finalize_loop_edges(read_pcm16(processed_path)))

    audio = np.concatenate(mastered_sections)
    output_path = OUT / "01-neon-circuit-clicksafe-v4.wav"
    preview_path = OUT / "01-neon-circuit-clicksafe-v4.m4a"
    runtime_path = ROOT / "assets" / "audio" / "neon-circuit-v1.m4a"
    write_pcm16(output_path, audio)
    subprocess.run(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-i", str(output_path),
            "-ar", str(RATE), "-c:a", "aac", "-b:a", "192k", str(preview_path),
        ],
        check=True,
    )
    runtime_path.write_bytes(preview_path.read_bytes())
    for path in temporary_paths:
        path.unlink()


if __name__ == "__main__":
    main()
