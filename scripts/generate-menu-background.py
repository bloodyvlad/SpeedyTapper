#!/usr/bin/env python3
"""Build the menu mix from Daylight Circuit and the approved tap-tone bank.

Gameplay keeps the backing-only Daylight Circuit runtime. The menu variant adds
the same sixteen-note Power Grid sequence at the song's 80 BPM beat spacing so
the menu has a melodic identity without creating an additional live cue system.
"""

from pathlib import Path
import subprocess
import wave

import numpy as np


RATE = 48_000
LOOP_SECONDS = 12
LOOP_FRAMES = RATE * LOOP_SECONDS
TONE_SLOT_SECONDS = 0.5
TONE_SLOT_FRAMES = int(RATE * TONE_SLOT_SECONDS)
BEAT_SECONDS = 0.75
TONE_GAIN = 0.20
ROOT = Path(__file__).resolve().parents[1]
BACKGROUND_PATH = ROOT / "assets/audio/background-masters/daylight-circuit.wav"
TONE_BANK_PATH = ROOT / "assets/audio/tap-tones.wav"
MASTER_PATH = ROOT / "assets/audio/background-masters/daylight-circuit-menu.wav"
RUNTIME_PATH = ROOT / "assets/audio/background-daylight-circuit-menu.m4a"


def read_pcm16(path, expected_channels):
    with wave.open(str(path), "rb") as source:
        if source.getframerate() != RATE:
            raise RuntimeError(f"{path.name} must use {RATE} Hz audio.")
        if source.getsampwidth() != 2:
            raise RuntimeError(f"{path.name} must use 16-bit PCM audio.")
        if source.getnchannels() != expected_channels:
            raise RuntimeError(
                f"{path.name} must contain {expected_channels} channel(s)."
            )
        frames = source.readframes(source.getnframes())
    return (
        np.frombuffer(frames, dtype="<i2")
        .reshape(-1, expected_channels)
        .astype(np.float64)
        / 32768.0
    )


def write_pcm16(path, audio):
    pcm = np.int16(np.clip(audio, -1, 1) * 32767)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(2)
        output.setsampwidth(2)
        output.setframerate(RATE)
        output.writeframes(pcm.astype("<i2").tobytes())


def render_menu_mix():
    background = read_pcm16(BACKGROUND_PATH, 2)
    tones = read_pcm16(TONE_BANK_PATH, 1)[:, 0]
    if len(background) != LOOP_FRAMES:
        raise RuntimeError("Daylight Circuit must be exactly twelve seconds.")
    if len(tones) < 16 * TONE_SLOT_FRAMES:
        raise RuntimeError("The tap-tone bank must contain sixteen complete slots.")

    mix = background.copy()
    pans = (-0.18, 0.10, -0.06, 0.18)
    for slot in range(16):
        start = int(round(slot * BEAT_SECONDS * RATE))
        tone_start = slot * TONE_SLOT_FRAMES
        tone = tones[tone_start:tone_start + TONE_SLOT_FRAMES]
        pan = pans[slot % len(pans)]
        left = np.sqrt((1 - pan) * 0.5)
        right = np.sqrt((1 + pan) * 0.5)
        end = min(start + len(tone), len(mix))
        count = end - start
        mix[start:end, 0] += tone[:count] * TONE_GAIN * left
        mix[start:end, 1] += tone[:count] * TONE_GAIN * right

    peak = np.max(np.abs(mix))
    if peak > 0.96:
        mix *= 0.96 / peak
    return mix


def generate():
    MASTER_PATH.parent.mkdir(parents=True, exist_ok=True)
    write_pcm16(MASTER_PATH, render_menu_mix())
    subprocess.run(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-i", str(MASTER_PATH), "-map_metadata", "-1",
            "-metadata", "title=Daylight Circuit Menu",
            "-metadata", "artist=SpeedyTapper",
            "-metadata", "comment=Original menu loop with Power Grid tones",
            "-c:a", "aac", "-b:a", "160k", "-ar", str(RATE),
            "-movflags", "+faststart", str(RUNTIME_PATH),
        ],
        check=True,
    )


if __name__ == "__main__":
    generate()
