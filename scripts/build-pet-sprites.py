#!/usr/bin/env python3
"""Build the ten-frame 64 px pet sprite sheets from retained source art.

The generated animals use accepted high-resolution alpha masters. Their old
32 px runtime sheets are retained only as layout templates so pose order,
baseline, and on-screen footprint stay unchanged. Pancake is the one exception:
its original recording is no longer available, so its accepted 32 px capture is
scaled losslessly with nearest-neighbour sampling rather than reinterpreted.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
PET_ROOT = ROOT / "assets" / "pets"
SOURCE_ROOT = PET_ROOT / "sources"
ALPHA_ROOT = SOURCE_ROOT / "alpha"
LAYOUT_ROOT = SOURCE_ROOT / "layout"

FRAME_COUNT = 10
LEGACY_FRAME_SIZE = 32
RUNTIME_FRAME_SIZE = 64
ALPHA_THRESHOLD = 8
RUNTIME_ALPHA_CUTOFF = 96


@dataclass(frozen=True)
class Component:
    area: int
    bbox: tuple[int, int, int, int]
    pixels: tuple[int, ...]

    @property
    def center_y(self) -> float:
        return (self.bbox[1] + self.bbox[3]) / 2


def _connected_components(image: Image.Image, minimum_area: int) -> list[Component]:
    """Return opaque 4-connected components large enough to be authored art."""

    alpha = image.getchannel("A")
    width, height = alpha.size
    alpha_pixels = alpha.load()
    visited = bytearray(width * height)
    components: list[Component] = []

    for y in range(height):
        for x in range(width):
            start = y * width + x
            if visited[start] or alpha_pixels[x, y] <= ALPHA_THRESHOLD:
                continue

            visited[start] = 1
            stack = [start]
            pixels: list[int] = []
            min_x = max_x = x
            min_y = max_y = y

            while stack:
                index = stack.pop()
                current_y, current_x = divmod(index, width)
                pixels.append(index)
                min_x = min(min_x, current_x)
                max_x = max(max_x, current_x)
                min_y = min(min_y, current_y)
                max_y = max(max_y, current_y)

                if current_x > 0:
                    neighbor = index - 1
                    if not visited[neighbor] and alpha_pixels[current_x - 1, current_y] > ALPHA_THRESHOLD:
                        visited[neighbor] = 1
                        stack.append(neighbor)
                if current_x + 1 < width:
                    neighbor = index + 1
                    if not visited[neighbor] and alpha_pixels[current_x + 1, current_y] > ALPHA_THRESHOLD:
                        visited[neighbor] = 1
                        stack.append(neighbor)
                if current_y > 0:
                    neighbor = index - width
                    if not visited[neighbor] and alpha_pixels[current_x, current_y - 1] > ALPHA_THRESHOLD:
                        visited[neighbor] = 1
                        stack.append(neighbor)
                if current_y + 1 < height:
                    neighbor = index + width
                    if not visited[neighbor] and alpha_pixels[current_x, current_y + 1] > ALPHA_THRESHOLD:
                        visited[neighbor] = 1
                        stack.append(neighbor)

            if len(pixels) >= minimum_area:
                components.append(
                    Component(
                        area=len(pixels),
                        bbox=(min_x, min_y, max_x + 1, max_y + 1),
                        pixels=tuple(pixels),
                    )
                )

    return components


def _component_image(source: Image.Image, component: Component) -> Image.Image:
    """Crop exactly one component so nearby motion marks cannot leak in."""

    left, top, right, bottom = component.bbox
    crop = source.crop(component.bbox)
    mask = Image.new("L", crop.size, 0)
    mask_pixels = mask.load()
    source_alpha = source.getchannel("A").load()
    source_width = source.width

    for index in component.pixels:
        y, x = divmod(index, source_width)
        mask_pixels[x - left, y - top] = source_alpha[x, y]

    crop.putalpha(mask)
    return crop


def _ordered_grid_components(
    path: Path,
    *,
    minimum_area: int = 10_000,
    maximum_bottom: int | None = None,
) -> list[Image.Image]:
    source = Image.open(path).convert("RGBA")
    components = _connected_components(source, minimum_area)
    if maximum_bottom is not None:
        components = [component for component in components if component.bbox[3] <= maximum_bottom]
    components.sort(key=lambda component: (component.center_y >= source.height / 2, component.bbox[0]))
    return [_component_image(source, component) for component in components]


def _single_component(path: Path, *, choose_topmost: bool = False) -> Image.Image:
    source = Image.open(path).convert("RGBA")
    components = _connected_components(source, 10_000)
    if not components:
        raise RuntimeError(f"No authored component found in {path}")
    component = min(components, key=lambda item: item.bbox[1]) if choose_topmost else max(
        components,
        key=lambda item: item.area,
    )
    return _component_image(source, component)


def _layout_boxes(pet_id: str) -> list[tuple[int, int, int, int]]:
    path = LAYOUT_ROOT / f"{pet_id}-runtime-32.png"
    layout = Image.open(path).convert("RGBA")
    if layout.size != (FRAME_COUNT * LEGACY_FRAME_SIZE, LEGACY_FRAME_SIZE):
        raise RuntimeError(f"Unexpected layout dimensions for {pet_id}: {layout.size}")

    boxes: list[tuple[int, int, int, int]] = []
    for frame_index in range(FRAME_COUNT):
        cell = layout.crop(
            (
                frame_index * LEGACY_FRAME_SIZE,
                0,
                (frame_index + 1) * LEGACY_FRAME_SIZE,
                LEGACY_FRAME_SIZE,
            )
        )
        bbox = cell.getchannel("A").getbbox()
        if bbox is None:
            raise RuntimeError(f"Layout frame {frame_index} for {pet_id} is empty")
        boxes.append(tuple(value * 2 for value in bbox))
    return boxes


def _remove_magenta_spill(frame: Image.Image) -> Image.Image:
    """Drop saturated key-colored edge pixels without touching warm pink art."""

    cleaned = frame.copy()
    pixels = cleaned.load()
    for y in range(cleaned.height):
        for x in range(cleaned.width):
            red, green, blue, alpha = pixels[x, y]
            dominance = min(red, blue) - green
            if alpha and max(red, blue) >= 60 and abs(red - blue) <= 80 and dominance >= 24:
                if dominance >= 44:
                    pixels[x, y] = (0, 0, 0, 0)
                else:
                    retained_alpha = round(alpha * (44 - dominance) / 20)
                    pixels[x, y] = (red, green, blue, retained_alpha)
    return cleaned


def _harden_runtime_alpha(frame: Image.Image) -> Image.Image:
    """Keep pixel-art edges binary so semi-transparent mattes cannot look soft."""

    hardened = frame.copy()
    pixels = hardened.load()
    for y in range(hardened.height):
        for x in range(hardened.width):
            red, green, blue, alpha = pixels[x, y]
            if alpha < RUNTIME_ALPHA_CUTOFF:
                pixels[x, y] = (0, 0, 0, 0)
            else:
                pixels[x, y] = (red, green, blue, 255)
    return hardened


def _write_generated_pet(
    pet_id: str,
    frames: list[Image.Image],
    *,
    remove_magenta_spill: bool = False,
) -> None:
    if len(frames) != FRAME_COUNT:
        raise RuntimeError(f"Expected ten {pet_id} frames, found {len(frames)}")

    sheet = Image.new("RGBA", (FRAME_COUNT * RUNTIME_FRAME_SIZE, RUNTIME_FRAME_SIZE), (0, 0, 0, 0))
    for frame_index, (frame, target_box) in enumerate(zip(frames, _layout_boxes(pet_id), strict=True)):
        left, top, right, bottom = target_box
        if remove_magenta_spill:
            frame = _remove_magenta_spill(frame)
        resized = frame.resize((right - left, bottom - top), Image.Resampling.NEAREST)
        resized = _harden_runtime_alpha(resized)
        sheet.alpha_composite(resized, (frame_index * RUNTIME_FRAME_SIZE + left, top))

    destination = PET_ROOT / f"{pet_id}-sprite.png"
    sheet.save(destination, format="PNG", optimize=True)
    print(f"Wrote {destination.relative_to(ROOT)} ({sheet.width}x{sheet.height})")


def _write_pancake() -> None:
    source = Image.open(LAYOUT_ROOT / "pancake-runtime-32.png").convert("RGBA")
    sheet = source.resize(
        (FRAME_COUNT * RUNTIME_FRAME_SIZE, RUNTIME_FRAME_SIZE),
        Image.Resampling.NEAREST,
    )
    destination = PET_ROOT / "pancake-sprite.png"
    sheet.save(destination, format="PNG", optimize=True)
    print(f"Wrote {destination.relative_to(ROOT)} ({sheet.width}x{sheet.height}; preserved capture)")


def main() -> None:
    for pet_id in ("foka", "kesha", "tauta"):
        frames = _ordered_grid_components(
            ALPHA_ROOT / f"{pet_id}-generated.png",
            maximum_bottom=720,
        )
        _write_generated_pet(pet_id, frames, remove_magenta_spill=True)

    misha_frames = _ordered_grid_components(ALPHA_ROOT / "misha-turn-generated.png")
    if len(misha_frames) != 8:
        raise RuntimeError(f"Expected eight Misha turn frames, found {len(misha_frames)}")
    misha_frames.extend(
        [
            _single_component(ALPHA_ROOT / "misha-transition-generated.png"),
            _single_component(ALPHA_ROOT / "misha-sleep-climber-generated.png", choose_topmost=True),
        ]
    )
    _write_generated_pet("misha", misha_frames, remove_magenta_spill=True)

    mitsuri_frames = _ordered_grid_components(ALPHA_ROOT / "mitsuri-generated.png")
    _write_generated_pet("mitsuri", mitsuri_frames)

    _write_pancake()


if __name__ == "__main__":
    main()
