/**
 * `<smking-carousel>` + `<smking-slide>` Web Component registration.
 * Inlined as a <script> by `<x-smking-cms>` (once per page via Blade
 * `@once`) so customer Laravel sites get carousel interactivity with
 * zero setup.
 *
 * Mirrors the Next.js SDK IIFE (smking-elements-iife.ts) and the
 * smking SaaS editor's smking-elements.ts. Keep all three in sync —
 * they register the same runtime.
 */
(function () {
  if (typeof window === "undefined" || typeof customElements === "undefined") return;
  var C = "smking-carousel", S = "smking-slide";
  if (customElements.get(C)) return;

  function nextFrame(cb) {
    if (typeof requestAnimationFrame !== "undefined") return requestAnimationFrame(cb);
    return setTimeout(cb, 16);
  }
  function cancelFrame(id) {
    if (typeof cancelAnimationFrame !== "undefined") return cancelAnimationFrame(id);
    return clearTimeout(id);
  }

  function CarouselEl() { return Reflect.construct(HTMLElement, [], CarouselEl); }
  CarouselEl.prototype = Object.create(HTMLElement.prototype);
  CarouselEl.prototype.constructor = CarouselEl;
  CarouselEl.prototype.connectedCallback = function () {
    var self = this;
    var viewport = self.querySelector(".smk-carousel__viewport");
    if (!viewport) return;
    var dotsContainer = self.querySelector(".smk-carousel__dots");
    var prevBtn = self.querySelector(".smk-carousel__nav-prev");
    var nextBtn = self.querySelector(".smk-carousel__nav-next");
    var slides = Array.prototype.slice.call(
      viewport.querySelectorAll(":scope > " + S),
    );
    if (dotsContainer) {
      var dotEls = slides.map(function (_, i) {
        var d = document.createElement("button");
        d.type = "button";
        d.className = "smk-carousel__dot";
        d.dataset.index = String(i);
        d.setAttribute("aria-label", "Go to slide " + (i + 1));
        if (i === 0) d.setAttribute("aria-current", "true");
        d.addEventListener("click", function (e) {
          e.preventDefault();
          viewport.scrollTo({ left: i * viewport.clientWidth, behavior: "smooth" });
        });
        return d;
      });
      dotsContainer.replaceChildren.apply(dotsContainer, dotEls);
    }
    function onPrev(e) {
      e.preventDefault();
      viewport.scrollBy({ left: -viewport.clientWidth, behavior: "smooth" });
    }
    function onNext(e) {
      e.preventDefault();
      viewport.scrollBy({ left: viewport.clientWidth, behavior: "smooth" });
    }
    if (prevBtn) prevBtn.addEventListener("click", onPrev);
    if (nextBtn) nextBtn.addEventListener("click", onNext);
    var raf = 0;
    function onScroll() {
      if (raf) return;
      raf = nextFrame(function () {
        raf = 0;
        var ai = Math.round(viewport.scrollLeft / Math.max(1, viewport.clientWidth));
        if (!dotsContainer) return;
        var dots = dotsContainer.querySelectorAll(".smk-carousel__dot");
        dots.forEach(function (d, i) {
          if (i === ai) d.setAttribute("aria-current", "true");
          else d.removeAttribute("aria-current");
        });
      });
    }
    viewport.addEventListener("scroll", onScroll, { passive: true });
    self._smkCleanup = function () {
      if (prevBtn) prevBtn.removeEventListener("click", onPrev);
      if (nextBtn) nextBtn.removeEventListener("click", onNext);
      viewport.removeEventListener("scroll", onScroll);
      if (raf) cancelFrame(raf);
    };
  };
  CarouselEl.prototype.disconnectedCallback = function () {
    if (this._smkCleanup) this._smkCleanup();
  };

  function SlideEl() { return Reflect.construct(HTMLElement, [], SlideEl); }
  SlideEl.prototype = Object.create(HTMLElement.prototype);
  SlideEl.prototype.constructor = SlideEl;

  customElements.define(C, CarouselEl);
  customElements.define(S, SlideEl);
})();
