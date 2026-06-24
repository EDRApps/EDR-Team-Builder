/* EDR availability grabber.
   Run this on a LOGGED-IN iRacePlan survey page (the Driver Availability Timeline).
   It reads each driver's name, car preferences and green availability blocks, then
   copies a JSON array to your clipboard. Paste it into the Team Builder's
   "Availability" box and press Merge availability.

   Windows are emitted as fractions (0..1) of the survey timeline, so they map onto
   whatever event window the Team Builder loaded. */
(function () {
  var isG = function (c) { return /bg-green-500/.test(c.className); };
  var isR = function (c) { return /bg-red-500/.test(c.className); };
  var isGr = function (c) { return /bg-zinc-(300|600|400|200|700)/.test(c.className); };
  var any = function (c) { return c.children.length === 0 && (isG(c) || isR(c) || isGr(c)); };

  var parents = new Map();
  document.querySelectorAll('div').forEach(function (e) {
    if (any(e)) { var p = e.parentElement; parents.set(p, (parents.get(p) || 0) + 1); }
  });
  var strips = [].concat.apply([], [...parents.entries()].filter(function (x) { return x[1] >= 20; }).map(function (x) { return [x[0]]; }));

  function nameFor(p) {
    var c = p;
    for (var k = 0; k < 6 && c; k++) {
      c = c.parentElement; if (!c) break;
      var cand = [...c.querySelectorAll('p,span,div,a')].find(function (e) {
        return e.children.length === 0 &&
          /^[A-Z][A-Za-z.]+\s+[A-Za-z]/.test(e.textContent.trim()) &&
          e.textContent.trim().length < 40 &&
          !/available|avail|%|GT3|P217|Mustang|Porsche|Ferrari|BMW|Audi|Dallara|Cadillac|Acura|Mercedes|Aston|Ford|IMSA|Lambo|Hurac/i.test(e.textContent);
      });
      if (cand) return cand.textContent.trim();
    }
    return '';
  }
  function carsFor(p) {
    var c = p;
    for (var k = 0; k < 6 && c; k++) {
      c = c.parentElement; if (!c) break;
      var cand = [...c.querySelectorAll('p,span,div')].find(function (e) {
        return e.children.length === 0 &&
          /(GT3|GT4|GTP|P217|Dallara|Porsche|Ferrari|BMW|Audi|Mustang|Mercedes|Aston|Cadillac|Acura|Lambo|Hurac)/i.test(e.textContent) &&
          e.textContent.trim().length < 90;
      });
      if (cand) return cand.textContent.trim();
    }
    return '';
  }

  var out = strips.map(function (p) {
    var cells = [...p.children].filter(any); if (!cells.length) return null;
    var rects = cells.map(function (c) { return { g: isG(c), r: c.getBoundingClientRect() }; });
    var left = Math.min.apply(null, rects.map(function (x) { return x.r.left; }));
    var right = Math.max.apply(null, rects.map(function (x) { return x.r.right; }));
    var W = right - left; if (W <= 0) return null;
    var wins = [];
    rects.forEach(function (o) {
      if (!o.g) return;
      var s = (o.r.left - left) / W, e = (o.r.right - left) / W;
      if (wins.length && s - wins[wins.length - 1][1] <= 0.01) wins[wins.length - 1][1] = e;
      else wins.push([s, e]);
    });
    return {
      name: nameFor(p),
      cars: carsFor(p),
      windows_frac: wins.map(function (w) { return [Math.round(w[0] * 1000) / 1000, Math.round(w[1] * 1000) / 1000]; })
    };
  }).filter(function (d) { return d && d.name; });

  var seen = {}, uniq = [];
  out.forEach(function (d) { if (!seen[d.name]) { seen[d.name] = 1; uniq.push(d); } });

  var json = JSON.stringify(uniq);
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(json).then(
      function () { alert('EDR: copied availability for ' + uniq.length + ' drivers. Paste it into the Team Builder.'); },
      function () { window.prompt('EDR availability (copy this):', json); }
    );
  } else {
    window.prompt('EDR availability (copy this):', json);
  }
})();
