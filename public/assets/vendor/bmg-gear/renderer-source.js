// Static-only adaptation of zynapse's shared gearRenderer; see PROVENANCE.json.
import {
  ACESFilmicToneMapping, DirectionalLight, Group, MeshStandardMaterial,
  OrthographicCamera, PMREMGenerator, Scene, WebGLRenderer
} from 'three';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { buildGear } from './gear-geometry.ts';

export const ILLUSTRATION = Object.freeze({
  mode: 'idle', speed: 0, progress: null,
  label: 'Illustration only — motor state and RPM are not measured.'
});
let shared = null;
const SIZE = 192;

function isDark() {
  const explicit = document.documentElement.dataset.theme;
  if (explicit === 'dark' || explicit === 'light') return explicit === 'dark';
  return document.documentElement.classList.contains('dark')
    || document.body?.classList.contains('dark')
    || document.body?.classList.contains('dark-mode')
    || document.body?.dataset.theme === 'dark';
}

function teardown(s) {
  if (shared === s) shared = null;
  if (s.raf !== null) cancelAnimationFrame(s.raf);
  s.io?.disconnect();
  s.themeObserver?.disconnect();
  document.removeEventListener('visibilitychange', s.visibility);
  s.renderer?.domElement.removeEventListener('webglcontextlost', s.lost);
  s.geometries?.forEach(g => g.dispose());
  s.material?.dispose();
  s.envTarget?.dispose();
  s.renderer?.dispose();
  s.renderer?.forceContextLoss();
  s.instances.clear();
}

function fail(s) {
  if (shared !== s) return;
  const instances = [...s.instances];
  teardown(s);
  instances.forEach(inst => inst.onFail());
}

function visible(inst) {
  if (!inst.canvas.isConnected || document.hidden || !inst.visible) return false;
  const box = inst.canvas.parentElement.getBoundingClientRect();
  return box.width > 0 && box.height > 0 && getComputedStyle(inst.canvas.parentElement).visibility !== 'hidden';
}

function schedule(s) {
  if (shared !== s || s.raf !== null || document.hidden) return;
  if (![...s.instances].some(inst => inst.dirty && visible(inst))) return;
  s.raf = requestAnimationFrame(() => {
    s.raf = null;
    try {
      for (const inst of s.instances) {
        if (!inst.dirty || !visible(inst)) continue;
        // Never consume batch state, status, speed, progress, or hover input.
        s.gear.rotation.z = inst.angle;
        s.material.color.set(isDark() ? '#3f4652' : '#7b828c');
        s.material.envMapIntensity = 0.4;
        s.renderer.render(s.scene, s.camera);
        inst.ctx.clearRect(0, 0, inst.canvas.width, inst.canvas.height);
        inst.ctx.drawImage(s.renderer.domElement, 0, 0, SIZE, SIZE,
          0, 0, inst.canvas.width, inst.canvas.height);
        inst.dirty = false;
        if (!inst.ready) { inst.ready = true; inst.onReady(); }
      }
    } catch (_) { fail(s); }
    // A stationary illustration has no recurring frame loop, even without reduced motion.
  });
}

function createShared() {
  const s = { instances: new Set(), byElement: new WeakMap(), raf: null };
  try {
    s.renderer = new WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'low-power' });
    s.renderer.setPixelRatio(1);
    s.renderer.setSize(SIZE, SIZE, false);
    s.renderer.setClearColor(0x000000, 0);
    s.renderer.toneMapping = ACESFilmicToneMapping;
    s.renderer.toneMappingExposure = 1.05;
    s.scene = new Scene();
    const pmrem = new PMREMGenerator(s.renderer);
    const room = new RoomEnvironment();
    s.renderer.debug.checkShaderErrors = false;
    try { s.envTarget = pmrem.fromScene(room, 0.04); }
    finally { room.dispose(); pmrem.dispose(); s.renderer.debug.checkShaderErrors = true; }
    s.scene.environment = s.envTarget.texture;
    const key = new DirectionalLight(0xffffff, 1.1);
    key.position.set(-2, 3, 5);
    s.scene.add(key);
    s.material = new MeshStandardMaterial({ metalness: 0.8, roughness: 0.38 });
    const { group, geometries } = buildGear(s.material);
    s.gear = group; s.geometries = geometries;
    const pivot = new Group();
    pivot.rotation.set(-0.3, 0.12, 0);
    pivot.add(group); s.scene.add(pivot);
    s.camera = new OrthographicCamera(-1.3, 1.3, 1.3, -1.3, 0.1, 20);
    s.camera.position.set(0, 0, 6);
    s.io = new IntersectionObserver(entries => {
      for (const entry of entries) {
        const inst = s.byElement.get(entry.target);
        if (inst) inst.visible = entry.isIntersecting;
      }
      schedule(s);
    });
    const repaint = () => { s.instances.forEach(inst => { inst.dirty = true; }); schedule(s); };
    s.themeObserver = new MutationObserver(repaint);
    for (const el of [document.documentElement, document.body]) {
      if (el) s.themeObserver.observe(el, { attributes: true, attributeFilter: ['class', 'data-theme', 'style', 'hidden'] });
    }
    s.visibility = () => {
      if (document.hidden && s.raf !== null) { cancelAnimationFrame(s.raf); s.raf = null; }
      else schedule(s);
    };
    document.addEventListener('visibilitychange', s.visibility);
    s.lost = event => { event.preventDefault(); fail(s); };
    s.renderer.domElement.addEventListener('webglcontextlost', s.lost);
    return s;
  } catch (_) { teardown(s); return null; }
}

/** One 2D output per gear; at most one shared offscreen WebGL context. */
export function attachGear(canvas, options = {}) {
  const ctx = canvas.getContext('2d');
  if (!ctx) return null;
  shared ??= createShared();
  const s = shared;
  if (!s) return null;
  const seed = Number.isSafeInteger(options.seed) ? options.seed : 0;
  const inst = { canvas, ctx, angle: (seed % 10000) * 0.9, dirty: true,
    visible: false, ready: false, onReady: options.onReady || (() => {}), onFail: options.onFail || (() => {}) };
  s.instances.add(inst);
  // Observe the wrapper, not the initially hidden canvas: otherwise first render never fires.
  const target = canvas.parentElement;
  s.byElement.set(target, inst); s.io.observe(target);
  let disposed = false;
  return Object.freeze({
    visual: ILLUSTRATION,
    dispose() {
      if (disposed) return;
      disposed = true;
      if (shared !== s) return;
      s.io.unobserve(target); s.byElement.delete(target); s.instances.delete(inst);
      if (!s.instances.size) teardown(s);
    }
  });
}
