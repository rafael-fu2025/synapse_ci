// Reused from zynapse gearModel.ts and gearRenderer.ts; see PROVENANCE.json.
import { Path, Shape, ExtrudeGeometry, Group, Mesh, type MeshStandardMaterial, type BufferGeometry } from 'three';
const TWO_PI = Math.PI * 2;
const PLATE_DEPTH = 0.3;
const HUB_DEPTH = 0.44;
export const GEAR_TEETH = 14;
/** Radius of the tooth valleys / tips, in scene units (tip = 1). */
export const GEAR_ROOT_RADIUS = 0.8;
export const GEAR_TIP_RADIUS = 1;

export type Point2 = readonly [x: number, y: number];

function polar(radius: number, angle: number): Point2 {
  return [radius * Math.cos(angle), radius * Math.sin(angle)];
}

/**
 * Closed, counter-clockwise outline of a spur gear: each tooth is a
 * trapezoid (wide at the root, narrow at the tip) and the valley between
 * two teeth is the straight chord between their roots. With 8+ teeth that
 * chord sits within a fraction of a percent of the true arc, so no
 * curve sampling is needed.
 *
 * Returns `teeth * 4` points, wound counter-clockwise (what three's
 * `Shape` expects for an outer contour).
 */
export function gearOutline(
  teeth: number = GEAR_TEETH,
  rootRadius: number = GEAR_ROOT_RADIUS,
  tipRadius: number = GEAR_TIP_RADIUS,
): Point2[] {
  if (!Number.isInteger(teeth) || teeth < 6) {
    throw new RangeError(`A gear needs at least 6 whole teeth, got ${teeth}`);
  }
  if (!(rootRadius > 0 && tipRadius > rootRadius)) {
    throw new RangeError('Tip radius must exceed a positive root radius');
  }

  const pitch = (Math.PI * 2) / teeth;
  const baseHalf = pitch * 0.3;
  const tipHalf = pitch * 0.15;
  const points: Point2[] = [];
  for (let i = 0; i < teeth; i += 1) {
    const centre = i * pitch;
    points.push(
      polar(rootRadius, centre - baseHalf),
      polar(tipRadius, centre - tipHalf),
      polar(tipRadius, centre + tipHalf),
      polar(rootRadius, centre + baseHalf),
    );
  }
  return points;
}

function circle(radius: number, clockwise: boolean, cx = 0, cy = 0): Path {
  const path = new Path();
  path.absarc(cx, cy, radius, 0, TWO_PI, clockwise);
  return path;
}

/** Toothed plate with a bore and six lightening holes, plus a raised hub. */
export function buildGear(material: MeshStandardMaterial): { group: Group; geometries: BufferGeometry[] } {
  const plateShape = new Shape();
  gearOutline().forEach(([x, y], i) => {
    if (i === 0) plateShape.moveTo(x, y);
    else plateShape.lineTo(x, y);
  });
  plateShape.closePath();
  // Holes wind the opposite way to the outer contour.
  plateShape.holes.push(circle(0.2, true));
  for (let i = 0; i < 6; i += 1) {
    const a = (i / 6) * TWO_PI + Math.PI / 6;
    plateShape.holes.push(circle(0.12, true, Math.cos(a) * 0.56, Math.sin(a) * 0.56));
  }
  const plate = new ExtrudeGeometry(plateShape, {
    depth: PLATE_DEPTH,
    bevelEnabled: true,
    bevelThickness: 0.025,
    bevelSize: 0.02,
    bevelSegments: 2,
    curveSegments: 20,
  });
  plate.translate(0, 0, -PLATE_DEPTH / 2);

  const hubShape = new Shape();
  hubShape.absarc(0, 0, 0.36, 0, TWO_PI, false);
  hubShape.holes.push(circle(0.2, true));
  const hub = new ExtrudeGeometry(hubShape, {
    depth: HUB_DEPTH,
    bevelEnabled: true,
    bevelThickness: 0.02,
    bevelSize: 0.015,
    bevelSegments: 2,
    curveSegments: 28,
  });
  hub.translate(0, 0, -HUB_DEPTH / 2);

  const group = new Group();
  group.add(new Mesh(plate, material), new Mesh(hub, material));
  return { group, geometries: [plate, hub] };
}

