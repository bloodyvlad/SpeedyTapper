#!/usr/bin/env python3
"""Generate original Disco, Light, and Pixel theme audio suites.

Each suite contains a twelve-second clean gameplay loop, a matching menu loop
with the theme's sixteen-note motif, and an eight-second click-safe tap-tone
bank. Synthesis is deterministic and uses no samples or external recordings.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import subprocess
import tempfile
import wave

import numpy as np


RATE = 48_000
LOOP_SECONDS = 12
LOOP_FRAMES = RATE * LOOP_SECONDS
TONE_SLOT_SECONDS = 0.5
TONE_SLOT_FRAMES = int(RATE * TONE_SLOT_SECONDS)
TONE_ACTIVE_FRAMES = int(RATE * 0.42)
EDGE_FADE_FRAMES = int(RATE * 0.005)
AAC_SEAM_LIMIT = 0.016
ROOT = Path(__file__).resolve().parents[1]
AUDIO_ROOT = ROOT / "assets/audio"
MASTER_ROOT = AUDIO_ROOT / "background-masters"


@dataclass(frozen=True)
class ThemeSpec:
    key: str
    title: str
    bpm: int
    chords: tuple[tuple[int, ...], ...]
    bass: tuple[int, ...]
    motif: tuple[int, ...]
    style: str
    menu_gain: float


THEMES = (
    ThemeSpec(
        key="disco",
        title="Mirror Circuit",
        bpm=120,
        chords=((57, 61, 64, 71), (55, 59, 62, 69), (54, 57, 61, 64), (55, 59, 62, 66)),
        bass=(45, 43, 42, 43),
        motif=(69, 73, 76, 73, 71, 74, 78, 74, 69, 71, 73, 76, 78, 76, 73, 71),
        style="disco",
        menu_gain=0.18,
    ),
    ThemeSpec(
        key="light",
        title="Open Sky",
        bpm=100,
        chords=((50, 54, 57, 64), (47, 50, 54, 57), (43, 47, 50, 57), (45, 49, 52, 59)),
        bass=(38, 35, 31, 33),
        motif=(74, 78, 81, 86, 83, 81, 78, 76, 74, 76, 78, 81, 83, 86, 85, 81),
        style="light",
        menu_gain=0.17,
    ),
    ThemeSpec(
        key="pixel",
        title="Coin-Op Spark",
        bpm=160,
        chords=((48, 52, 55, 66), (45, 48, 52, 59), (41, 45, 48, 55), (43, 47, 50, 57)),
        bass=(36, 33, 29, 31),
        motif=(72, 76, 79, 78, 76, 83, 79, 78, 72, 74, 76, 79, 81, 83, 86, 84),
        style="pixel",
        menu_gain=0.15,
    ),
)


def hz(midi_note: int) -> float:
    return 440.0 * 2 ** ((midi_note - 69) / 12)


def smooth_envelope(frames: int, attack: float, release: float) -> np.ndarray:
    envelope = np.ones(frames, dtype=np.float64)
    attack_frames = min(frames, max(1, int(round(attack * RATE))))
    release_frames = min(frames, max(1, int(round(release * RATE))))
    envelope[:attack_frames] *= np.sin(np.linspace(0, np.pi / 2, attack_frames)) ** 2
    envelope[-release_frames:] *= np.cos(np.linspace(0, np.pi / 2, release_frames)) ** 2
    envelope[0] = 0
    envelope[-1] = 0
    return envelope


def deterministic_noise(frames: int, seed: int) -> np.ndarray:
    return np.random.default_rng(seed).uniform(-1, 1, frames)


def soft_pad(notes: tuple[int, ...], duration: float, phase: float, style: str) -> np.ndarray:
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    signal = np.zeros(frames, dtype=np.float64)
    brightness = {"disco": 0.15, "light": 0.09, "pixel": 0.20}[style]
    for index, note in enumerate(notes):
        frequency = hz(note)
        detune = 1 + (index - 1.5) * (0.00042 if style != "pixel" else 0.00010)
        note_phase = phase + index * 0.41
        signal += np.sin(2 * np.pi * frequency * detune * time + note_phase)
        signal += brightness * np.sin(2 * np.pi * frequency * 2 * time + note_phase * 1.23)
        if style == "light":
            signal += 0.035 * np.sin(2 * np.pi * frequency * 3 * time + note_phase * 0.73)
    signal /= len(notes)
    motion_rate = {"disco": 0.24, "light": 0.08, "pixel": 0.34}[style]
    motion = 0.90 + 0.10 * np.sin(2 * np.pi * motion_rate * time + phase)
    return signal * motion * smooth_envelope(frames, 0.24, 0.55) * 0.15


def bass_note(note: int, duration: float, style: str, accent: float = 1.0) -> np.ndarray:
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    frequency = hz(note)
    phase = 2 * np.pi * frequency * time
    if style == "pixel":
        signal = sum(((-1) ** index) * np.sin((2 * index + 1) * phase) / ((2 * index + 1) ** 2)
                     for index in range(4))
    else:
        signal = np.sin(phase) + 0.12 * np.sin(2 * phase + 0.2)
    envelope = smooth_envelope(frames, 0.012, min(0.18, duration * 0.45))
    envelope *= np.exp(-time / max(0.08, duration * 0.75))
    level = {"disco": 0.19, "light": 0.14, "pixel": 0.16}[style]
    return np.tanh(signal * 0.92) * envelope * level * accent


def kick(style: str, duration: float = 0.28) -> np.ndarray:
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    start, end = {"disco": (105, 54), "light": (86, 52), "pixel": (130, 60)}[style]
    frequency = end + (start - end) * np.exp(-time * 24)
    phase = 2 * np.pi * np.cumsum(frequency) / RATE
    envelope = smooth_envelope(frames, 0.004, 0.075) * np.exp(-time * 11)
    level = {"disco": 0.24, "light": 0.12, "pixel": 0.18}[style]
    return np.sin(phase) * envelope * level


def clap(style: str, seed: int, duration: float = 0.17) -> np.ndarray:
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    noise = deterministic_noise(frames, seed)
    # First differences remove most low-frequency noise without a filter dependency.
    bright = np.concatenate(([0.0], np.diff(noise)))
    envelope = smooth_envelope(frames, 0.003, 0.05) * np.exp(-time * 22)
    tonal = np.sin(2 * np.pi * hz(83 if style == "light" else 78) * time) * 0.18
    level = {"disco": 0.072, "light": 0.038, "pixel": 0.055}[style]
    return (bright * 0.72 + tonal) * envelope * level


def chord_pulse(notes: tuple[int, ...], duration: float, style: str) -> np.ndarray:
    frames = int(round(duration * RATE))
    time = np.arange(frames, dtype=np.float64) / RATE
    signal = np.zeros(frames, dtype=np.float64)
    for index, note in enumerate(notes):
        phase = 2 * np.pi * hz(note + 12) * time
        if style == "pixel":
            signal += sum(np.sin((harmonic * 2 + 1) * phase) / (harmonic * 2 + 1)
                          for harmonic in range(3))
        else:
            signal += np.sin(phase) + 0.08 * np.sin(2 * phase + index * 0.4)
    signal /= len(notes)
    envelope = smooth_envelope(frames, 0.014, min(0.16, duration * 0.55))
    envelope *= np.exp(-time * (5.8 if style == "disco" else 4.2))
    return signal * envelope * {"disco": 0.075, "light": 0.055, "pixel": 0.052}[style]


def place(bus: np.ndarray, sound: np.ndarray, start: int, gain: float = 1.0, pan: float = 0.0) -> None:
    if start >= len(bus) or start + len(sound) <= 0:
        return
    source_start = max(0, -start)
    destination_start = max(0, start)
    count = min(len(sound) - source_start, len(bus) - destination_start)
    if count <= 0:
        return
    segment = sound[source_start:source_start + count] * gain
    left = np.sqrt((1 - pan) * 0.5)
    right = np.sqrt((1 + pan) * 0.5)
    bus[destination_start:destination_start + count, 0] += segment * left
    bus[destination_start:destination_start + count, 1] += segment * right


def render_background(spec: ThemeSpec) -> np.ndarray:
    total_frames = LOOP_FRAMES * 3
    bus = np.zeros((total_frames, 2), dtype=np.float64)
    beat_frames = int(round((60 / spec.bpm) * RATE))
    beats_per_loop = round(LOOP_SECONDS * spec.bpm / 60)
    bars_per_loop = beats_per_loop // 4

    for repetition in range(3):
        loop_start = repetition * LOOP_FRAMES
        for bar in range(bars_per_loop):
            chord_index = bar % len(spec.chords)
            chord = spec.chords[chord_index]
            bar_start = loop_start + bar * beat_frames * 4
            pad = soft_pad(chord, (beat_frames * 4) / RATE + 0.72, bar * 0.37, spec.style)
            place(bus, pad, bar_start - int(0.22 * RATE), pan=0.08 if bar % 2 == 0 else -0.08)

            for beat in range(4):
                beat_start = bar_start + beat * beat_frames
                if spec.style == "disco" or beat in (0, 2):
                    place(bus, kick(spec.style), beat_start, gain=0.86 if beat else 1.0)
                if beat in (0, 2) or spec.style == "pixel":
                    bass_start = beat_start + (beat_frames // 2 if spec.style == "disco" and beat == 2 else 0)
                    place(bus, bass_note(spec.bass[chord_index], beat_frames / RATE * 0.88,
                                         spec.style, 1.0 if beat == 0 else 0.76), bass_start)
                if beat in (1, 3):
                    place(bus, clap(spec.style, repetition * 100 + bar * 10 + beat), beat_start,
                          pan=-0.18 if beat == 1 else 0.18)

                pulse_offset = beat_frames // 2 if spec.style != "pixel" else beat_frames // 4
                place(bus, chord_pulse(chord, 0.24 if spec.style != "light" else 0.38, spec.style),
                      beat_start + pulse_offset, pan=0.15 if beat % 2 == 0 else -0.15)

    dry = bus.copy()
    delays = {
        "disco": ((0.092, 0.055), (0.188, 0.026)),
        "light": ((0.136, 0.060), (0.294, 0.026)),
        "pixel": ((0.061, 0.040), (0.122, 0.020)),
    }[spec.style]
    for delay_seconds, gain in delays:
        shift = int(round(delay_seconds * RATE))
        bus[shift:, 0] += dry[:-shift, 1] * gain
        bus[shift:, 1] += dry[:-shift, 0] * gain
    return np.tanh(bus * 1.03) / np.tanh(1.03)


def render_tone(note: int, style: str) -> np.ndarray:
    frames = TONE_ACTIVE_FRAMES
    time = np.arange(frames, dtype=np.float64) / RATE
    frequency = hz(note)
    phase = 2 * np.pi * frequency * time
    if style == "disco":
        signal = np.sin(phase) + 0.24 * np.sin(2 * phase + 0.24) + 0.08 * np.sin(3 * phase)
        signal += 0.055 * np.sin(2 * np.pi * frequency * 1.006 * time + 0.6)
        decay = np.exp(-time * 4.9)
        attack = 0.007
    elif style == "light":
        signal = np.sin(phase) + 0.26 * np.sin(2.01 * phase + 0.38)
        signal += 0.13 * np.sin(3.995 * phase + 0.11) + 0.045 * np.sin(6.01 * phase)
        decay = np.exp(-time * 4.0)
        attack = 0.009
    else:
        signal = sum(np.sin((2 * harmonic + 1) * phase) / (2 * harmonic + 1)
                     for harmonic in range(5))
        signal += 0.12 * sum(((-1) ** harmonic) * np.sin((harmonic + 1) * phase) / ((harmonic + 1) ** 2)
                             for harmonic in range(4))
        decay = np.exp(-time * 6.2)
        attack = 0.003
    envelope = smooth_envelope(frames, attack, 0.075) * decay
    tone = signal * envelope
    rms = np.sqrt(np.mean(tone ** 2))
    if rms <= 0:
        raise RuntimeError("Generated tone is silent.")
    tone *= 0.205 / rms
    peak = np.max(np.abs(tone))
    if peak > 0.78:
        tone *= 0.78 / peak
    return tone


def render_tone_bank(spec: ThemeSpec) -> np.ndarray:
    bank = np.zeros(TONE_SLOT_FRAMES * len(spec.motif), dtype=np.float64)
    for index, note in enumerate(spec.motif):
        tone = render_tone(note, spec.style)
        start = index * TONE_SLOT_FRAMES
        bank[start:start + len(tone)] = tone
    return bank


def render_menu(background: np.ndarray, tone_bank: np.ndarray, spec: ThemeSpec) -> np.ndarray:
    menu = background.copy()
    pans = (-0.20, 0.10, -0.08, 0.22)
    for slot in range(16):
        start = int(round(slot * 0.75 * RATE))
        tone = tone_bank[slot * TONE_SLOT_FRAMES:(slot + 1) * TONE_SLOT_FRAMES]
        pan = pans[slot % len(pans)]
        left = np.sqrt((1 - pan) * 0.5)
        right = np.sqrt((1 + pan) * 0.5)
        count = min(len(tone), len(menu) - start)
        menu[start:start + count, 0] += tone[:count] * spec.menu_gain * left
        menu[start:start + count, 1] += tone[:count] * spec.menu_gain * right
    # Keep extra sample and inter-sample headroom for iPhone AAC decoding. The
    # bright menu motif contains more high-frequency energy than the clean run
    # loop, so matching only the background limiter is not sufficient.
    peak = np.max(np.abs(menu))
    if peak > 0.50:
        menu *= 0.50 / peak
    return menu


def condition_loop_edges(audio: np.ndarray) -> np.ndarray:
    """Fade five milliseconds to exact zero so AAC edge ringing stays inaudible."""
    conditioned = audio.copy()
    ramp = np.sin(np.linspace(0, np.pi / 2, EDGE_FADE_FRAMES)) ** 2
    conditioned[:EDGE_FADE_FRAMES] *= ramp[:, np.newaxis]
    conditioned[-EDGE_FADE_FRAMES:] *= ramp[::-1, np.newaxis]
    conditioned[0] = 0
    conditioned[-1] = 0
    return conditioned


def write_pcm16(path: Path, audio: np.ndarray, channels: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    pcm = np.int16(np.clip(audio, -1, 1) * 32767)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(channels)
        output.setsampwidth(2)
        output.setframerate(RATE)
        output.writeframes(pcm.astype("<i2").tobytes())


def write_pcm32(path: Path, audio: np.ndarray) -> None:
    pcm = np.int32(np.clip(audio, -1, 1) * 2_147_483_647)
    with wave.open(str(path), "wb") as output:
        output.setnchannels(2)
        output.setsampwidth(4)
        output.setframerate(RATE)
        output.writeframes(pcm.astype("<i4").tobytes())


def read_pcm16(path: Path, channels: int) -> np.ndarray:
    with wave.open(str(path), "rb") as source:
        if source.getframerate() != RATE or source.getsampwidth() != 2 or source.getnchannels() != channels:
            raise RuntimeError(f"Unexpected WAV format for {path}.")
        frames = source.readframes(source.getnframes())
    return np.frombuffer(frames, dtype="<i2").reshape(-1, channels).astype(np.float64) / 32768.0


def encode_aac(master: Path, runtime: Path, title: str, comment: str) -> None:
    subprocess.run(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-i", str(master), "-map_metadata", "-1",
            "-metadata", f"title={title}", "-metadata", "artist=SpeedyTapper",
            "-metadata", f"comment={comment}",
            "-c:a", "aac", "-b:a", "160k", "-ar", str(RATE),
            "-movflags", "+faststart", str(runtime),
        ],
        check=True,
    )


def verify_aac_loop(runtime: Path) -> None:
    decoded = subprocess.run(
        [
            "ffmpeg", "-hide_banner", "-loglevel", "error",
            "-i", str(runtime), "-f", "f32le", "-acodec", "pcm_f32le", "pipe:1",
        ],
        check=True,
        stdout=subprocess.PIPE,
    ).stdout
    samples = np.frombuffer(decoded, dtype="<f4")
    if len(samples) < LOOP_FRAMES * 2:
        raise RuntimeError(f"{runtime.name} decoded shorter than the authored loop.")
    authored_loop = samples[:LOOP_FRAMES * 2].reshape(LOOP_FRAMES, 2)
    seam = float(np.max(np.abs(authored_loop[0] - authored_loop[-1])))
    if seam > AAC_SEAM_LIMIT:
        raise RuntimeError(
            f"{runtime.name} decoded seam {seam:.6f} exceeds {AAC_SEAM_LIMIT:.3f}."
        )


def generate_theme(spec: ThemeSpec) -> None:
    suite_root = AUDIO_ROOT / "themes" / spec.key
    tone_path = suite_root / "tap-tones.wav"
    background_master = MASTER_ROOT / f"{spec.key}-{spec.title.lower().replace(' ', '-')}.wav"
    menu_master = MASTER_ROOT / f"{spec.key}-{spec.title.lower().replace(' ', '-')}-menu.wav"
    background_runtime = suite_root / "background.m4a"
    menu_runtime = suite_root / "menu.m4a"

    tone_bank = render_tone_bank(spec)
    write_pcm16(tone_path, tone_bank, 1)

    with tempfile.TemporaryDirectory(prefix=f"speedytapper-{spec.key}-") as temporary:
        temporary_root = Path(temporary)
        raw = temporary_root / "three-loops.wav"
        mastered = temporary_root / "three-loops-mastered.wav"
        write_pcm32(raw, render_background(spec))
        subprocess.run(
            [
                "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                "-i", str(raw),
                "-af",
                (
                    "highpass=f=42,lowpass=f=8500,"
                    "acompressor=threshold=0.20:ratio=1.65:attack=24:release=210:makeup=1.04,"
                    "loudnorm=I=-18.0:LRA=4:TP=-4.2"
                ),
                "-ar", str(RATE), "-c:a", "pcm_s16le", str(mastered),
            ],
            check=True,
        )
        mastered_audio = read_pcm16(mastered, 2)
        clean_loop = mastered_audio[LOOP_FRAMES:LOOP_FRAMES * 2]
        if len(clean_loop) != LOOP_FRAMES:
            raise RuntimeError(f"{spec.title} did not render a complete loop.")
        write_pcm16(background_master, condition_loop_edges(clean_loop), 2)

    menu_loop = render_menu(read_pcm16(background_master, 2), tone_bank, spec)
    with tempfile.TemporaryDirectory(prefix=f"speedytapper-{spec.key}-menu-") as temporary:
        temporary_root = Path(temporary)
        raw_menu = temporary_root / "menu-raw.wav"
        mastered_menu = temporary_root / "menu-mastered.wav"
        write_pcm32(raw_menu, condition_loop_edges(menu_loop))
        subprocess.run(
            [
                "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                "-i", str(raw_menu),
                "-af",
                (
                    "loudnorm=I=-18.0:LRA=4:TP=-4.2,"
                    "alimiter=limit=0.46:attack=5:release=50:level=false"
                ),
                "-ar", str(RATE), "-c:a", "pcm_s16le", str(mastered_menu),
            ],
            check=True,
        )
        mastered_menu_audio = read_pcm16(mastered_menu, 2)
        if len(mastered_menu_audio) != LOOP_FRAMES:
            raise RuntimeError(f"{spec.title} menu did not render a complete loop.")
        write_pcm16(menu_master, condition_loop_edges(mastered_menu_audio), 2)
    encode_aac(background_master, background_runtime, f"{spec.title} Background",
               f"Original {spec.key} theme gameplay loop")
    encode_aac(menu_master, menu_runtime, f"{spec.title} Menu",
               f"Original {spec.key} theme menu loop with matching tap motif")
    verify_aac_loop(background_runtime)
    verify_aac_loop(menu_runtime)


def main() -> None:
    for spec in THEMES:
        generate_theme(spec)


if __name__ == "__main__":
    main()
