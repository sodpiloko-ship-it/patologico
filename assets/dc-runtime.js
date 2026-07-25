/* dc-runtime — ejecuta en produccion las plantillas de Claude Design (.dc.html).
 *
 * El diseno viene con su propia logica (`class Component extends DCLogic`) y un
 * markup declarativo: {{ binding }}, <sc-if>, <sc-for>, style-hover, onClick,
 * <image-slot>. Este runtime reimplementa ese contrato con DOM plano (0 deps)
 * para que el sitio publicado sea EL MISMO diseno, no una copia a mano.
 *
 * Regla: la logica del diseno se copia VERBATIM. Todo lo de produccion
 * (persistencia, analitica) se conecta por fuera via hooks, nunca editando
 * renderVals(). Asi reimportar un rediseno es volver a correr dc_compile.py.
 */
(function (global) {
  'use strict';

  // ---------- utilidades de binding ----------

  // Resuelve "a.b.c" contra la cadena de scopes (el mas interno gana).
  function resolvePath(path, scopes) {
    var parts = path.split('.');
    for (var i = scopes.length - 1; i >= 0; i--) {
      var cur = scopes[i];
      if (cur == null || !(parts[0] in cur)) continue;
      for (var j = 0; j < parts.length && cur != null; j++) cur = cur[parts[j]];
      return cur;
    }
    return undefined;
  }

  var LITERALS = { 'true': true, 'false': false, 'null': null, 'undefined': undefined };

  function evalExpr(expr, scopes) {
    expr = expr.trim();
    if (expr in LITERALS) return LITERALS[expr];
    if (/^-?\d+(\.\d+)?$/.test(expr)) return Number(expr);
    if (/^'[^']*'$/.test(expr) || /^"[^"]*"$/.test(expr)) return expr.slice(1, -1);
    return resolvePath(expr, scopes);
  }

  var BINDING_RE = /\{\{([^}]*)\}\}/g;

  // ¿La cadena es exactamente UN binding? Entonces devolvemos el valor crudo
  // (funcion, booleano, array) en vez de su representacion en texto.
  function soleBinding(str) {
    var m = /^\s*\{\{([^}]*)\}\}\s*$/.exec(str);
    return m ? m[1] : null;
  }

  // Siempre sustitucion en sitio: NO se recorta lo que rodea al binding. Un
  // "{{ n }} <span>{{ pct }}</span>" tiene que dejar el espacio, o en pantalla
  // se lee "41100%" en vez de "41 100%". Los valores crudos (funciones,
  // booleanos) se resuelven con soleBinding donde de verdad hacen falta:
  // onClick, sc-if, sc-for y dc-html.
  function interpolate(str, scopes) {
    return str.replace(BINDING_RE, function (_, expr) {
      var v = evalExpr(expr, scopes);
      return v == null ? '' : String(v);
    });
  }

  // ---------- style-hover / style-focus -> reglas CSS reales ----------
  // Un listener por nodo no sobrevive al re-render y rompe en tactil; una clase
  // generada si. Se memoiza por (pseudo, declaracion) para no inflar el <style>.

  var sheet = null;
  var styleCache = Object.create(null);
  var styleSeq = 0;

  function pseudoClass(pseudo, declaration) {
    var key = pseudo + '|' + declaration;
    if (styleCache[key]) return styleCache[key];
    if (!sheet) {
      var el = document.createElement('style');
      el.setAttribute('data-dc-runtime', '');
      document.head.appendChild(el);
      sheet = el.sheet;
    }
    var cls = 'dch' + (++styleSeq);
    try {
      sheet.insertRule('.' + cls + ':' + pseudo + '{' + declaration + '}', sheet.cssRules.length);
    } catch (e) { /* declaracion invalida: se ignora, no debe tumbar la pagina */ }
    styleCache[key] = cls;
    return cls;
  }

  // ---------- <image-slot> ----------
  // En el editor es un hueco; en produccion es una <img> real, o un placeholder
  // sobrio si todavia no hay foto (mejor un hueco honesto que una imagen rota).

  function buildImageSlot(attrs) {
    var src = attrs.src;
    if (src) {
      var img = document.createElement('img');
      img.src = src;
      img.alt = attrs.alt || attrs.placeholder || '';
      img.loading = attrs.loading || 'lazy';
      img.decoding = 'async';
      img.style.cssText = 'width:100%;height:100%;display:block;object-fit:' +
        (attrs.fit || 'cover') + ';object-position:' + (attrs.position || 'center');
      return img;
    }
    var box = document.createElement('div');
    box.setAttribute('data-image-slot', attrs.id || '');
    box.style.cssText = 'width:100%;height:100%;display:flex;align-items:center;' +
      'justify-content:center;text-align:center;padding:18px;background:' +
      'repeating-linear-gradient(135deg,#E7DAC2,#E7DAC2 12px,#E2D3B7 12px,#E2D3B7 24px);' +
      "color:#9A886C;font:500 12.5px/1.45 'Hanken Grotesk',system-ui,sans-serif";
    box.textContent = attrs.placeholder || '';
    return box;
  }

  // ---------- eventos ----------

  var EVENTS = {
    onclick: 'click', oninput: 'input', onchange: 'change',
    onsubmit: 'submit', onfocus: 'focus', onblur: 'blur', onkeydown: 'keydown'
  };

  // ---------- render ----------

  function renderNode(tpl, scopes, out) {
    // texto
    if (tpl.nodeType === 3) {
      var txt = tpl.nodeValue;
      if (txt.indexOf('{{') === -1) {
        if (txt.trim() || /[ \n]/.test(txt)) out.appendChild(document.createTextNode(txt));
      } else {
        out.appendChild(document.createTextNode(interpolate(txt, scopes)));
      }
      return;
    }
    if (tpl.nodeType !== 1) return;

    var tag = tpl.tagName.toLowerCase();

    // <sc-if value="{{ x }}">
    if (tag === 'sc-if') {
      var cond = evalExpr(soleBinding(tpl.getAttribute('value') || '') || 'false', scopes);
      if (cond) renderChildren(tpl, scopes, out);
      return;
    }

    // <sc-for list="{{ arr }}" as="item">
    if (tag === 'sc-for') {
      var list = evalExpr(soleBinding(tpl.getAttribute('list') || '') || '', scopes);
      var alias = tpl.getAttribute('as') || 'item';
      if (!Array.isArray(list)) return;
      for (var i = 0; i < list.length; i++) {
        var scope = {};
        scope[alias] = list[i];
        scope[alias + 'Index'] = i;
        renderChildren(tpl, scopes.concat([scope]), out);
      }
      return;
    }

    // <image-slot>
    if (tag === 'image-slot') {
      var a = {};
      for (var k = 0; k < tpl.attributes.length; k++) {
        var at = tpl.attributes[k];
        a[at.name] = interpolate(at.value, scopes);
      }
      out.appendChild(buildImageSlot(a));
      return;
    }

    var el = document.createElement(tag);
    var classes = [];

    for (var n = 0; n < tpl.attributes.length; n++) {
      var attr = tpl.attributes[n];
      var name = attr.name;
      var raw = attr.value;
      var lower = name.toLowerCase();

      // style-hover / style-focus / style-active -> clase con pseudo real
      if (lower.indexOf('style-') === 0) {
        var pseudo = lower.slice(6);
        var decl = interpolate(raw, scopes);
        if (decl) classes.push(pseudoClass(pseudo, decl));
        continue;
      }

      // onClick="{{ fn }}" -> listener
      if (EVENTS[lower]) {
        var only = soleBinding(raw);
        var fn = only !== null ? evalExpr(only, scopes) : null;
        if (typeof fn === 'function') el.addEventListener(EVENTS[lower], fn);
        continue;
      }

      // hint-* son ayudas del editor, no van a produccion
      if (lower.indexOf('hint-') === 0) continue;

      // dc-html: inyecta HTML en vez de texto. Valvula EXPLICITA y unica, para
      // contenido PROPIO (los articulos del Diario, que viven en este dominio).
      // Nunca pasar por aqui nada que venga de un tercero o de un formulario.
      if (lower === 'dc-html') {
        var only2 = soleBinding(raw);
        el.innerHTML = only2 !== null ? (evalExpr(only2, scopes) || '') : interpolate(raw, scopes);
        continue;
      }

      var val = interpolate(raw, scopes);

      // value en inputs debe ser PROPIEDAD (el atributo no refresca el campo)
      if (lower === 'value' && (tag === 'input' || tag === 'textarea' || tag === 'select')) {
        el.value = val;
        el.setAttribute('value', val);
        continue;
      }
      if (lower === 'checked' || lower === 'disabled' || lower === 'open' || lower === 'selected') {
        if (val && val !== 'false') el.setAttribute(lower, '');
        continue;
      }
      if (lower === 'class') { classes.push(val); continue; }

      try { el.setAttribute(name, val); } catch (e) { /* nombre invalido */ }
    }

    if (classes.length) el.className = classes.join(' ');

    renderChildren(tpl, scopes, el);
    out.appendChild(el);
  }

  function renderChildren(tpl, scopes, out) {
    var kids = tpl.childNodes;
    for (var i = 0; i < kids.length; i++) renderNode(kids[i], scopes, out);
  }

  // ---------- foco ----------
  // Re-render completo = simple y correcto, pero perderia el cursor mientras
  // se escribe. Guardamos la ruta por indices y la restauramos.

  function focusPath(root) {
    var el = document.activeElement;
    if (!el || !root.contains(el)) return null;
    var path = [];
    while (el && el !== root) {
      var p = el.parentNode;
      if (!p) return null;
      path.unshift(Array.prototype.indexOf.call(p.childNodes, el));
      el = p;
    }
    var a = document.activeElement;
    return {
      path: path,
      start: 'selectionStart' in a ? a.selectionStart : null,
      end: 'selectionEnd' in a ? a.selectionEnd : null
    };
  }

  function restoreFocus(root, saved) {
    if (!saved) return;
    var node = root;
    for (var i = 0; i < saved.path.length; i++) {
      node = node && node.childNodes[saved.path[i]];
      if (!node) return;
    }
    if (!node.focus) return;
    node.focus();
    if (saved.start != null && 'setSelectionRange' in node) {
      try { node.setSelectionRange(saved.start, saved.end); } catch (e) { /* type sin seleccion */ }
    }
  }

  // ---------- clase base ----------

  function DCLogic(props) {
    this.props = props || {};
    this.state = {};
  }
  DCLogic.prototype.setState = function (patch) {
    var next = typeof patch === 'function' ? patch(this.state) : patch;
    var merged = {}, k;
    for (k in this.state) merged[k] = this.state[k];
    for (k in next) merged[k] = next[k];
    this.state = merged;
    if (this._notify) this._notify(next);
  };
  DCLogic.prototype.renderVals = function () { return {}; };

  // ---------- montaje ----------

  /**
   * @param {Element} root  contenedor que trae la plantilla como innerHTML
   * @param {DCLogic} logic instancia de la logica del diseno
   * @param {Object}  opts  { onState(patch, state) — hook de persistencia }
   */
  function mount(root, logic, opts) {
    opts = opts || {};
    var template = document.createElement('div');
    template.innerHTML = root.innerHTML;   // plantilla congelada
    root.innerHTML = '';

    var queued = false;
    function render() {
      queued = false;
      var vals;
      try {
        vals = logic.renderVals() || {};
      } catch (err) {
        if (global.console) console.error('[dc] renderVals fallo:', err);
        return;
      }
      var saved = focusPath(root);
      var frag = document.createDocumentFragment();
      renderChildren(template, [vals], frag);
      root.innerHTML = '';
      root.appendChild(frag);
      restoreFocus(root, saved);
      if (opts.onRender) opts.onRender(vals);
    }

    // rAF agrupa varios setState en un solo repintado, pero NO se dispara si la
    // pestaña esta oculta: el carrito se quedaria sin actualizar. Con la pagina
    // en segundo plano caemos a setTimeout, que siempre corre.
    function schedule(fn) {
      if (global.requestAnimationFrame && !document.hidden) global.requestAnimationFrame(fn);
      else setTimeout(fn, 0);
    }

    logic._notify = function (patch) {
      if (opts.onState) {
        try { opts.onState(patch, logic.state); } catch (e) { if (global.console) console.error('[dc] onState:', e); }
      }
      if (!queued) { queued = true; schedule(render); }
    };

    render();

    // Ciclo de vida: se llama UNA vez, despues del primer pintado. Es donde el
    // diseno arranca temporizadores o lee localStorage (p.ej. el pop-up de
    // temporada, que no debe salir dos veces a la misma persona).
    if (typeof logic.componentDidMount === 'function') {
      try { logic.componentDidMount(); } catch (e) { if (global.console) console.error('[dc] componentDidMount:', e); }
    }

    return { render: render, logic: logic };
  }

  global.DCLogic = DCLogic;
  global.dcMount = mount;
})(window);
