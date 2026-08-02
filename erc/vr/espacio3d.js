/**
 * ERC — MOTOR DE ESPACIO NAVEGABLE
 *
 * Vendemos el POTENCIAL de un espacio, no su foto. Por eso el espacio se construye
 * PARAMETRICAMENTE desde medidas (largo, ancho, alto, ventanas, puertas): asi la luz,
 * el color de los muros y —mas adelante— los muebles se pueden cambiar en vivo.
 *
 * Nota de arquitectura, porque decide todo lo demas: un render fotogrametrico (Gaussian
 * Splatting / NeRF sacado de video) captura el espacio TAL COMO ESTA, con su luz y su
 * color YA HORNEADOS en el modelo. Se ve espectacular, pero por eso mismo NO se le puede
 * cambiar la hora del dia ni repintar un muro sin pelear contra la representacion.
 * Este motor resuelve la capa EDITABLE. La captura fotorrealista entra despues como
 * segundo modo ("asi esta hoy"), no como sustituto.
 *
 * Sin dependencias mas alla de three.js vendorizado.
 */

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

// --------------------------------------------------------------------------- utilidades
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const lerp = (a, b, t) => a + (b - a) * t;

/** Color de la luz solar segun su altura: calido al amanecer/atardecer, neutro al mediodia. */
function colorDelSol(altura) {
    // altura: -0.2 (bajo el horizonte) .. 1 (cenit)
    const t = clamp((altura + 0.1) / 0.6, 0, 1);
    const amanecer = new THREE.Color(0xff9a4d);   // 2200K aprox
    const medio    = new THREE.Color(0xfff4e8);   // 5600K aprox
    return amanecer.clone().lerp(medio, t);
}

/** Color del cielo: azul profundo de noche, celeste de dia, naranja en los extremos. */
function colorDelCielo(altura) {
    const noche  = new THREE.Color(0x0b1023);
    const bajo   = new THREE.Color(0xe8825a);
    const dia    = new THREE.Color(0x9fc4e8);
    if (altura <= 0) {
        return noche.clone().lerp(bajo, clamp((altura + 0.18) / 0.18, 0, 1));
    }
    return bajo.clone().lerp(dia, clamp(altura / 0.35, 0, 1));
}

/** Posicion del sol para una hora (0-24). Modelo simple pero con la forma correcta:
 *  sale por el oriente, culmina al mediodia, se pone por el poniente. */
function posicionSolar(hora, radio = 60) {
    const t = (hora - 6) / 12;                     // 0 al amanecer, 1 al atardecer
    const angulo = t * Math.PI;                    // 0..PI
    const altura = Math.sin(angulo);               // negativo antes de las 6 y despues de las 18
    return {
        altura,
        vector: new THREE.Vector3(
            -Math.cos(angulo) * radio,             // oriente -> poniente
            altura * radio,
            Math.cos(angulo * 0.6) * radio * 0.35  // ligera inclinacion, no un arco plano
        ),
    };
}

// --------------------------------------------------------------------------- el espacio
export class Espacio3D {
    /**
     * @param {HTMLElement} contenedor
     * @param {object} espacio  definicion del inmueble (ver data/espacios.json)
     */
    constructor(contenedor, espacio) {
        this.contenedor = contenedor;
        this.def = espacio;
        this.hora = 13;
        this.colorMuro = espacio.muro_color || '#EDE8E0';
        this.colorPiso = espacio.piso_color || '#B98D5F';
        this._disposiciones = [];

        this._iniciarEscena();
        this._construirEspacio();
        this._colocarCamara();
        this.setHora(this.hora);
        this._animar();

        this._onResize = () => this._ajustar();
        window.addEventListener('resize', this._onResize);
    }

    // ---------------------------------------------------------------- escena
    _iniciarEscena() {
        const { clientWidth: w, clientHeight: h } = this.contenedor;
        this.escena = new THREE.Scene();

        this.render3d = new THREE.WebGLRenderer({
            antialias: true, alpha: false,
            // Sin esto, capturar() devuelve una imagen en blanco: el navegador limpia el
            // buffer al presentar el frame.
            preserveDrawingBuffer: true,
        });
        this.render3d.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.render3d.setSize(w, h || 480);
        this.render3d.shadowMap.enabled = true;
        this.render3d.shadowMap.type = THREE.PCFSoftShadowMap;
        // Tono filmico: sin esto los interiores se ven planos y lavados.
        this.render3d.toneMapping = THREE.ACESFilmicToneMapping;
        this.render3d.toneMappingExposure = 1.0;
        this.contenedor.appendChild(this.render3d.domElement);

        this.camara = new THREE.PerspectiveCamera(62, (w || 800) / (h || 480), 0.05, 400);

        this.controles = new OrbitControls(this.camara, this.render3d.domElement);
        this.controles.enableDamping = true;
        this.controles.dampingFactor = 0.07;
        this.controles.maxPolarAngle = Math.PI * 0.86;   // no atravesar el piso
        this.controles.minDistance = 0.6;
        this.controles.maxDistance = 26;

        // Luces: el sol (direccional con sombra) y el rebote del cielo/piso.
        this.sol = new THREE.DirectionalLight(0xffffff, 3);
        this.sol.castShadow = true;
        this.sol.shadow.mapSize.set(2048, 2048);
        this.sol.shadow.camera.near = 0.5;
        this.sol.shadow.camera.far = 160;
        const s = 16;
        Object.assign(this.sol.shadow.camera, { left: -s, right: s, top: s, bottom: -s });
        this.sol.shadow.bias = -0.0008;
        this.escena.add(this.sol, this.sol.target);

        this.cielo = new THREE.HemisphereLight(0xbfd8ff, 0x6b5a44, 0.7);
        this.escena.add(this.cielo);

        // Luz interior artificial: se enciende sola cuando cae el sol.
        this.lampara = new THREE.PointLight(0xffd9a0, 0, 18, 2);
        this.escena.add(this.lampara);
    }

    // ---------------------------------------------------------------- geometria
    _mat(color, rugosidad = 0.92, metal = 0) {
        return new THREE.MeshStandardMaterial({
            color: new THREE.Color(color), roughness: rugosidad, metalness: metal,
        });
    }

    _limpiar() {
        for (const o of this._disposiciones) {
            this.escena.remove(o);
            o.geometry && o.geometry.dispose();
            o.material && (Array.isArray(o.material)
                ? o.material.forEach(m => m.dispose()) : o.material.dispose());
        }
        this._disposiciones = [];
    }

    /** Muro con huecos: se arma por tiras para dejar el vano de ventanas y puertas. */
    _muroConHuecos(ancho, alto, huecos, material) {
        const grupo = new THREE.Group();
        const bordes = [0];
        huecos.forEach(h => { bordes.push(h.x - h.ancho / 2, h.x + h.ancho / 2); });
        bordes.push(ancho);
        bordes.sort((a, b) => a - b);

        for (let i = 0; i < bordes.length - 1; i++) {
            const x0 = bordes[i], x1 = bordes[i + 1];
            const w = x1 - x0;
            if (w <= 0.001) continue;
            const centro = (x0 + x1) / 2;
            const hueco = huecos.find(h => Math.abs(h.x - centro) < h.ancho / 2 - 0.001);

            if (!hueco) {
                const m = new THREE.Mesh(new THREE.PlaneGeometry(w, alto), material);
                m.position.set(centro - ancho / 2, alto / 2, 0);
                m.receiveShadow = true; m.castShadow = true;
                grupo.add(m);
            } else {
                // antepecho y dintel: lo que queda arriba y abajo del vano
                const abajo = hueco.y;
                const arriba = alto - (hueco.y + hueco.alto);
                if (abajo > 0.001) {
                    const m = new THREE.Mesh(new THREE.PlaneGeometry(w, abajo), material);
                    m.position.set(centro - ancho / 2, abajo / 2, 0);
                    m.receiveShadow = true; m.castShadow = true; grupo.add(m);
                }
                if (arriba > 0.001) {
                    const m = new THREE.Mesh(new THREE.PlaneGeometry(w, arriba), material);
                    m.position.set(centro - ancho / 2, alto - arriba / 2, 0);
                    m.receiveShadow = true; m.castShadow = true; grupo.add(m);
                }
            }
        }
        return grupo;
    }

    _construirEspacio() {
        this._limpiar();
        const d = this.def;
        const L = d.largo || 7, A = d.ancho || 5, H = d.alto || 2.7;

        this.matMuro = this._mat(this.colorMuro, 0.96);
        const matPiso = this._mat(this.colorPiso, 0.55);
        const matTecho = this._mat('#F7F5F1', 0.98);

        // piso
        const piso = new THREE.Mesh(new THREE.PlaneGeometry(L, A), matPiso);
        piso.rotation.x = -Math.PI / 2;
        piso.receiveShadow = true;
        this.escena.add(piso); this._disposiciones.push(piso);

        // techo
        const techo = new THREE.Mesh(new THREE.PlaneGeometry(L, A), matTecho);
        techo.rotation.x = Math.PI / 2;
        techo.position.y = H;
        techo.castShadow = true; techo.receiveShadow = true;
        this.escena.add(techo); this._disposiciones.push(techo);

        // Los cuatro muros, mirando hacia adentro. Cada uno puede llevar huecos.
        const huecosPor = (lado) => (d.huecos || []).filter(h => h.muro === lado);
        const config = [
            { lado: 'norte', ancho: L, pos: [0, 0, -A / 2], rotY: 0 },
            { lado: 'sur',   ancho: L, pos: [0, 0,  A / 2], rotY: Math.PI },
            { lado: 'oeste', ancho: A, pos: [-L / 2, 0, 0], rotY: Math.PI / 2 },
            { lado: 'este',  ancho: A, pos: [ L / 2, 0, 0], rotY: -Math.PI / 2 },
        ];
        this.muros = [];
        for (const c of config) {
            const g = this._muroConHuecos(c.ancho, H, huecosPor(c.lado), this.matMuro);
            g.position.set(...c.pos);
            g.rotation.y = c.rotY;
            this.escena.add(g);
            this._disposiciones.push(g);
            this.muros.push(g);
        }

        // Marco de cada vano: CUATRO PERFILES, no una placa. Una caja del tamano del vano
        // lo taparia por completo — se veria un rectangulo oscuro donde deberia entrar luz.
        const matMarco = this._mat('#3B3B37', 0.6, 0.1);
        const matVidrio = new THREE.MeshPhysicalMaterial({
            color: 0xcfe4f2, roughness: 0.06, metalness: 0,
            transmission: 0.92, thickness: 0.01, transparent: true, opacity: 0.28,
        });
        const G = 0.06;                                   // grosor del perfil
        for (const c of config) {
            for (const h of huecosPor(c.lado)) {
                const grupo = new THREE.Group();
                const barras = [
                    [h.ancho + G * 2, G, 0, h.alto / 2 + G / 2],   // dintel
                    [h.ancho + G * 2, G, 0, -h.alto / 2 - G / 2],  // antepecho
                    [G, h.alto, -h.ancho / 2 - G / 2, 0],          // jamba izquierda
                    [G, h.alto,  h.ancho / 2 + G / 2, 0],          // jamba derecha
                ];
                for (const [bw, bh, bx, by] of barras) {
                    const m = new THREE.Mesh(new THREE.BoxGeometry(bw, bh, 0.09), matMarco);
                    m.position.set(bx, by, 0);
                    m.castShadow = true; m.receiveShadow = true;
                    grupo.add(m);
                }
                // Vidrio: deja pasar la luz y da lectura de que hay cristal, no un agujero.
                if ((h.tipo || 'ventana') === 'ventana') {
                    const v = new THREE.Mesh(new THREE.PlaneGeometry(h.ancho, h.alto), matVidrio);
                    grupo.add(v);
                }
                grupo.position.set(...c.pos);
                grupo.rotation.y = c.rotY;
                grupo.translateX(h.x - c.ancho / 2);
                grupo.translateY(h.y + h.alto / 2);
                this.escena.add(grupo); this._disposiciones.push(grupo);
            }
        }

        // Exterior: un plano de suelo grande para que por la ventana no se vea el vacio.
        const exterior = new THREE.Mesh(
            new THREE.PlaneGeometry(240, 240), this._mat('#8a9a7b', 1));
        exterior.rotation.x = -Math.PI / 2;
        exterior.position.y = -0.02;
        this.escena.add(exterior); this._disposiciones.push(exterior);

        this.dim = { L, A, H };
    }

    _colocarCamara() {
        const { L, A, H } = this.dim;
        // A la altura de los ojos, en una esquina: asi se lee el volumen del cuarto.
        this.camara.position.set(L * 0.34, H * 0.62, A * 0.34);
        this.controles.target.set(0, H * 0.42, 0);
        this.controles.update();
    }

    // ---------------------------------------------------------------- controles vivos
    /** Hora del dia (0-24): mueve el sol, cambia su color y enciende la luz interior. */
    setHora(hora) {
        this.hora = clamp(Number(hora) || 0, 0, 24);
        const { altura, vector } = posicionSolar(this.hora);
        const dia = altura > 0;

        this.sol.position.copy(vector);
        this.sol.target.position.set(0, 0, 0);
        this.sol.target.updateMatrixWorld();
        this.sol.color.copy(colorDelSol(altura));
        this.sol.intensity = dia ? lerp(0.5, 3.4, clamp(altura, 0, 1)) : 0;
        this.sol.visible = dia;

        const cielo = colorDelCielo(altura);
        this.escena.background = cielo;
        this.escena.fog = new THREE.Fog(cielo, 40, 190);
        this.cielo.color.copy(cielo);
        this.cielo.intensity = dia ? lerp(0.25, 0.85, clamp(altura, 0, 1)) : 0.12;

        // Al caer el sol se enciende la luz de la casa: el espacio nunca queda a oscuras.
        const { L, A, H } = this.dim;
        this.lampara.position.set(0, H - 0.35, 0);
        this.lampara.distance = Math.max(L, A) * 2.2;
        this.lampara.intensity = dia ? lerp(6, 0, clamp(altura * 3, 0, 1)) : 9;

        this.render3d.toneMappingExposure = dia ? lerp(0.85, 1.05, clamp(altura, 0, 1)) : 1.15;
        return this;
    }

    /** Repinta los muros en vivo. */
    setColorMuro(hex) {
        this.colorMuro = hex;
        this.matMuro.color.set(hex);
        return this;
    }

    /** Cambia el piso en vivo. */
    setColorPiso(hex) {
        this.colorPiso = hex;
        const piso = this._disposiciones.find(o => o.isMesh && o.rotation.x < -1);
        if (piso) piso.material.color.set(hex);
        return this;
    }

    /** Carga otro inmueble sin recrear el visor. */
    cargar(espacio) {
        this.def = espacio;
        this.colorMuro = espacio.muro_color || this.colorMuro;
        this.colorPiso = espacio.piso_color || this.colorPiso;
        this._construirEspacio();
        this._colocarCamara();
        this.setHora(this.hora);
        return this;
    }

    /** Foto del render actual (para la ficha de la propiedad o para compartir). */
    capturar() {
        this.render3d.render(this.escena, this.camara);
        return this.render3d.domElement.toDataURL('image/png');
    }

    _ajustar() {
        const { clientWidth: w, clientHeight: h } = this.contenedor;
        if (!w || !h) return;
        this.camara.aspect = w / h;
        this.camara.updateProjectionMatrix();
        this.render3d.setSize(w, h);
    }

    _animar() {
        this._raf = requestAnimationFrame(() => this._animar());
        this.controles.update();
        this.render3d.render(this.escena, this.camara);
    }

    destruir() {
        cancelAnimationFrame(this._raf);
        window.removeEventListener('resize', this._onResize);
        this._limpiar();
        this.render3d.dispose();
        this.contenedor.innerHTML = '';
    }
}

export default Espacio3D;
