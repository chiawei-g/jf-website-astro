/* JF Self Defense — shared client script
   Handles tweaks (cross-page persistence via localStorage),
   plus the host-side __edit_mode protocol so the Tweaks toolbar toggle works.
*/

(function () {
  const STORAGE_KEY = "jfsd.tweaks.v1";

  // Tweak schema
  const DEFAULTS = {
    bg: "charcoal",          // charcoal | black | paper
    headline: "humanist",    // humanist | condensed
    tagline: "fight",        // fight | softer | ready
  };

  const TAGLINES = {
    fight: {
      l1: "When you have to fight,",
      l2: "FIGHT TO <span class=\"red em\">WIN</span>",
      flat: "When you have to fight, fight to WIN.",
    },
    softer: {
      l1: "Train for the day",
      l2: "YOU HOPE <span class=\"red em\">NEVER COMES</span>",
      flat: "Train for the day you hope never COMES.",
    },
    ready: {
      l1: "Be ready. Stay alert.",
      l2: "WALK AWAY <span class=\"red em\">WHEN YOU CAN</span>",
      flat: "Be ready. Stay alert. Walk away when you CAN.",
    },
  };

  function read() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return { ...DEFAULTS };
      return { ...DEFAULTS, ...JSON.parse(raw) };
    } catch (e) {
      return { ...DEFAULTS };
    }
  }
  function write(state) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {}
  }

  function apply(state) {
    const html = document.documentElement;
    html.setAttribute("data-bg", state.bg);
    html.setAttribute("data-headline", state.headline);

    // Tagline injection (only on home hero)
    const tagL1 = document.querySelector("[data-tagline-l1]");
    const tagL2 = document.querySelector("[data-tagline-l2]");
    if (tagL1 && tagL2 && TAGLINES[state.tagline]) {
      tagL1.innerHTML = TAGLINES[state.tagline].l1;
      tagL2.innerHTML = TAGLINES[state.tagline].l2;
    }
    // Plain repeated tagline (booking CTA on home)
    document.querySelectorAll("[data-tagline-flat]").forEach((el) => {
      if (TAGLINES[state.tagline]) el.textContent = TAGLINES[state.tagline].flat;
    });
  }

  // ----- Tweaks Panel -----
  let panelVisible = false;
  let editModeActive = false;

  function buildPanel() {
    let panel = document.getElementById("__jfsd_tweaks");
    if (panel) return panel;
    const state = read();
    panel = document.createElement("div");
    panel.id = "__jfsd_tweaks";
    panel.innerHTML = `
      <style>
        #__jfsd_tweaks {
          position: fixed;
          bottom: 24px;
          right: 24px;
          z-index: 200;
          width: 320px;
          background: #0E0E10;
          color: #EFEBE4;
          border: 1px solid rgba(239,235,228,0.22);
          font-family: 'Inter Tight', 'Helvetica Neue', sans-serif;
          box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        #__jfsd_tweaks header {
          display: flex; justify-content: space-between; align-items: center;
          padding: 14px 18px; border-bottom: 1px solid rgba(239,235,228,0.12);
        }
        #__jfsd_tweaks h3 {
          margin: 0;
          font-family: 'JetBrains Mono', ui-monospace, monospace;
          font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase;
          color: #C2BFB8; font-weight: 500;
        }
        #__jfsd_tweaks h3 .dot {
          display: inline-block; width: 6px; height: 6px;
          background: #C8302C; border-radius: 50%; margin-right: 8px;
          vertical-align: middle; transform: translateY(-1px);
        }
        #__jfsd_tweaks .close {
          background: none; border: 0; color: #8A8780; cursor: pointer;
          font-size: 18px; line-height: 1; padding: 0;
        }
        #__jfsd_tweaks .close:hover { color: #EFEBE4; }
        #__jfsd_tweaks .body { padding: 18px; display: flex; flex-direction: column; gap: 18px; }
        #__jfsd_tweaks .group label {
          font-family: 'JetBrains Mono', ui-monospace, monospace;
          font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase;
          color: #8A8780; display: block; margin-bottom: 8px;
        }
        #__jfsd_tweaks .seg {
          display: grid; grid-auto-flow: column; grid-auto-columns: 1fr;
          border: 1px solid rgba(239,235,228,0.22);
        }
        #__jfsd_tweaks .seg button {
          padding: 9px 4px; font-size: 12px; color: #C2BFB8;
          background: transparent; border: 0; cursor: pointer;
          border-right: 1px solid rgba(239,235,228,0.12);
          font-family: inherit; letter-spacing: 0.01em;
        }
        #__jfsd_tweaks .seg button:last-child { border-right: 0; }
        #__jfsd_tweaks .seg button.active {
          background: #C8302C; color: #fff;
        }
        #__jfsd_tweaks .seg button:not(.active):hover { color: #EFEBE4; background: rgba(255,255,255,0.04); }
      </style>
      <header>
        <h3><span class="dot"></span>Tweaks</h3>
        <button class="close" aria-label="Close">×</button>
      </header>
      <div class="body">
        <div class="group">
          <label>Headline font</label>
          <div class="seg" data-key="headline">
            <button data-val="humanist">Humanist</button>
            <button data-val="condensed">Condensed</button>
          </div>
        </div>
        <div class="group">
          <label>Tagline</label>
          <div class="seg" data-key="tagline">
            <button data-val="fight">Fight to win</button>
            <button data-val="softer">Train for</button>
            <button data-val="ready">Be ready</button>
          </div>
        </div>
        <div class="group">
          <label>Background</label>
          <div class="seg" data-key="bg">
            <button data-val="charcoal">Charcoal</button>
            <button data-val="black">True black</button>
            <button data-val="paper">Paper</button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(panel);

    // wire
    panel.querySelector(".close").addEventListener("click", () => hidePanel());
    panel.querySelectorAll(".seg").forEach((seg) => {
      const key = seg.getAttribute("data-key");
      seg.querySelectorAll("button").forEach((btn) => {
        btn.addEventListener("click", () => {
          const val = btn.getAttribute("data-val");
          const state = read();
          state[key] = val;
          write(state);
          apply(state);
          syncPanelActive(panel, state);
          // Persist tweaks to host so they survive reload (editmode block)
          try {
            window.parent.postMessage(
              { type: "__edit_mode_set_keys", edits: { [key]: val } },
              "*"
            );
          } catch (e) {}
        });
      });
    });
    syncPanelActive(panel, read());
    return panel;
  }

  function syncPanelActive(panel, state) {
    panel.querySelectorAll(".seg").forEach((seg) => {
      const key = seg.getAttribute("data-key");
      seg.querySelectorAll("button").forEach((btn) => {
        btn.classList.toggle("active", btn.getAttribute("data-val") === state[key]);
      });
    });
  }

  function showPanel() {
    const panel = buildPanel();
    panel.style.display = "block";
    panelVisible = true;
  }
  function hidePanel() {
    const panel = document.getElementById("__jfsd_tweaks");
    if (panel) panel.style.display = "none";
    panelVisible = false;
    try {
      window.parent.postMessage({ type: "__edit_mode_dismissed" }, "*");
    } catch (e) {}
  }

  // ----- Host edit-mode protocol -----
  window.addEventListener("message", (e) => {
    const data = e.data || {};
    if (data.type === "__activate_edit_mode") {
      editModeActive = true;
      showPanel();
    } else if (data.type === "__deactivate_edit_mode") {
      editModeActive = false;
      hidePanel();
    }
  });

  // Apply state on every page load
  apply(read());

  // ----- TRIAL MODAL -----
  function buildTrialModal() {
    if (document.getElementById("__trial_modal")) return;
    const modal = document.createElement("div");
    modal.id = "__trial_modal";
    modal.className = "trial-modal";
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "__trial_title");
    modal.innerHTML = `
      <div class="trial-modal-backdrop" data-close></div>
      <div class="trial-modal-card">
        <button class="trial-modal-close" data-close aria-label="Close">×</button>
        <div class="trial-modal-head">
          <span class="eyebrow"><span class="dot"></span>BOOK A FREE TRIAL</span>
          <h3 id="__trial_title">Your first class, on the house.</h3>
          <p>Tell us a little about you. Jeffrey will reach out within 24&nbsp;hours to confirm a date and answer questions. No payment, no commitment.</p>
        </div>
        ${renderTrialForm("modal")}
      </div>
    `;
    document.body.appendChild(modal);
    wireForm(modal.querySelector(".trial-form"));
    modal.querySelectorAll("[data-close]").forEach((el) =>
      el.addEventListener("click", closeTrial)
    );
  }

  function renderTrialForm(scope) {
    const id = (k) => `__tf_${scope}_${k}`;
    return `
      <form class="trial-form" novalidate>
        <div class="trial-form-success">
          Thanks — Jeffrey will reach out within 24 hours to confirm your trial.<br/>
          If you need to reach him sooner: <a href="https://wa.me/6596210576" style="color:var(--red);" target="_blank" rel="noopener">WhatsApp +65 9621 0576</a>.
        </div>
        <div class="trial-form-error" role="alert"></div>
        <div class="row">
          <label for="${id('name')}">Your name
            <input id="${id('name')}" name="name" required autocomplete="name" />
          </label>
          <label for="${id('email')}">Email
            <input id="${id('email')}" name="email" type="email" required autocomplete="email" />
          </label>
        </div>
        <div class="row">
          <label for="${id('phone')}">Phone
            <input id="${id('phone')}" name="phone" type="tel" required autocomplete="tel" placeholder="WhatsApp preferred" />
          </label>
          <label for="${id('programme')}">Programme of interest
            <select id="${id('programme')}" name="programme">
              <option>Group training</option>
              <option>Women's self defense</option>
              <option>Personalised / Private 1-on-1</option>
              <option>Kids &amp; families</option>
              <option>Corporate workshop</option>
              <option>Security personnel</option>
              <option>Not sure yet — recommend something</option>
            </select>
          </label>
        </div>
        <label for="${id('when')}">Best days &amp; times to train <span style="opacity:0.6;">— optional</span>
          <input id="${id('when')}" name="when" placeholder="e.g. weekday evenings, Saturday mornings" />
        </label>
        <label for="${id('message')}">Anything else?
          <textarea id="${id('message')}" name="message" placeholder="Goals, injuries, prior experience, group size if corporate…"></textarea>
        </label>
        <input type="text" name="_hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-10000px;opacity:0;height:0;width:0;pointer-events:none;" aria-hidden="true" />
        <div class="trial-form-actions">
          <button class="trial-form-submit" type="submit">
            Send trial request
            <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </button>
          <a class="whatsapp-secondary" href="https://wa.me/6596210576?text=Hi%20Jeffrey%2C%20I%27d%20like%20to%20book%20a%20trial%20session." target="_blank" rel="noopener">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 2.1.55 4.15 1.6 5.96L2 22l4.25-1.11a9.9 9.9 0 0 0 5.79 1.85h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.91-7.02A9.84 9.84 0 0 0 12.04 2Z"/></svg>
            Prefer WhatsApp? +65 9621 0576
          </a>
        </div>
        <p class="trial-form-note">By submitting you agree to be contacted by JF Self Defense about your enquiry. No spam — Jeffrey replies personally.</p>
      </form>
    `;
  }

  function wireForm(form) {
    if (!form || form.__wired) return;
    form.__wired = true;

    const errorBox = form.querySelector(".trial-form-error");
    const success = form.querySelector(".trial-form-success");
    const submitBtn = form.querySelector(".trial-form-submit");

    function showError(msg) {
      if (errorBox) {
        errorBox.textContent = msg;
        errorBox.classList.add("is-shown");
      } else {
        console.error("[trial-form]", msg);
      }
    }
    function clearError() {
      if (errorBox) {
        errorBox.textContent = "";
        errorBox.classList.remove("is-shown");
      }
    }

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      clearError();

      const data = new FormData(form);
      const payload = {
        name: (data.get("name") || "").toString().trim(),
        email: (data.get("email") || "").toString().trim(),
        phone: (data.get("phone") || "").toString().trim(),
        programme: (data.get("programme") || "").toString().trim(),
        when: (data.get("when") || "").toString().trim(),
        message: (data.get("message") || "").toString().trim(),
        _hp: (data.get("_hp") || "").toString(),
      };

      // Client-side required-field check.
      const missing = [];
      if (!payload.name) missing.push("name");
      if (!payload.email) missing.push("email");
      if (!payload.phone) missing.push("phone");
      if (missing.length) {
        form.querySelectorAll("input[required]").forEach((inp) => {
          if (!inp.value) inp.style.borderColor = "var(--red)";
        });
        showError("Please fill in your name, email, and phone.");
        return;
      }

      // Disable the button while we submit.
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.dataset.originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = "Sending…";
      }

      try {
        const res = await fetch("submit-trial.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });
        const out = await res.json().catch(() => ({}));
        if (!res.ok || !out.ok) {
          throw new Error(out.error || ("HTTP " + res.status));
        }
        form.classList.add("is-sent");
        if (success) success.classList.add("is-shown");
      } catch (err) {
        showError(
          "Sorry — we couldn't send that just now. Please WhatsApp Jeffrey at +65 9621 0576 or email Jeffrey.f@jfselfdefense.com directly."
        );
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = submitBtn.dataset.originalText || "Send trial request";
        }
      }
    });

    // Clear red border + error on input.
    form.querySelectorAll("input, textarea, select").forEach((inp) => {
      inp.addEventListener("input", () => {
        inp.style.borderColor = "";
        clearError();
      });
    });
  }

  function openTrial(e) {
    if (e && e.preventDefault) e.preventDefault();
    buildTrialModal();
    const modal = document.getElementById("__trial_modal");
    if (!modal) return;
    modal.classList.add("is-open");
    document.body.style.overflow = "hidden";

    // Pre-select programme if trigger has data-trial-programme
    const trigger = e && e.currentTarget;
    const prog = trigger && trigger.getAttribute && trigger.getAttribute("data-trial-programme");
    if (prog) {
      const sel = modal.querySelector('select[name="programme"]');
      if (sel) {
        const target = prog.replace(/&amp;/g, "&");
        for (const opt of sel.options) {
          if (opt.value === target || opt.textContent.trim() === target) {
            sel.value = opt.value;
            break;
          }
        }
      }
    }

    // Reset success state if reopening
    const f = modal.querySelector(".trial-form");
    if (f) {
      f.classList.remove("is-sent");
      const s = f.querySelector(".trial-form-success");
      if (s) s.classList.remove("is-shown");
    }

    const firstInput = modal.querySelector("input, select, textarea");
    setTimeout(() => firstInput && firstInput.focus(), 80);
  }
  function closeTrial() {
    const modal = document.getElementById("__trial_modal");
    if (modal) {
      modal.classList.remove("is-open");
      document.body.style.overflow = "";
    }
  }

  // Wire all [data-trial] elements + inline forms
  function wireTriggers() {
    document.querySelectorAll("[data-trial]").forEach((el) => {
      if (el.__trialWired) return;
      el.__trialWired = true;
      el.addEventListener("click", openTrial);
    });
    document.querySelectorAll(".trial-form[data-inline]").forEach((form) => wireForm(form));
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", wireTriggers);
  } else {
    wireTriggers();
  }
  // Render inline form into placeholders
  document.querySelectorAll("[data-inline-trial-form]").forEach((placeholder) => {
    placeholder.innerHTML = renderTrialForm("inline");
    const f = placeholder.querySelector(".trial-form");
    if (f) {
      f.setAttribute("data-inline", "");
      wireForm(f);
    }
  });

  // ESC to close modal
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeTrial();
  });

  // Announce availability after listener is live
  try {
    window.parent.postMessage({ type: "__edit_mode_available" }, "*");
  } catch (e) {}

  // ----- MOBILE NAV TOGGLE -----
  function initMobileNav() {
    const toggle = document.querySelector("[data-nav-toggle]");
    const dropdown = document.querySelector("[data-nav-dropdown]");
    if (!toggle || !dropdown) return;

    function close() {
      toggle.classList.remove("is-open");
      dropdown.classList.remove("is-open");
      toggle.setAttribute("aria-expanded", "false");
      dropdown.setAttribute("aria-hidden", "true");
      document.body.classList.remove("nav-locked");
    }
    function open() {
      toggle.classList.add("is-open");
      dropdown.classList.add("is-open");
      toggle.setAttribute("aria-expanded", "true");
      dropdown.setAttribute("aria-hidden", "false");
      document.body.classList.add("nav-locked");
    }

    toggle.addEventListener("click", () => {
      if (dropdown.classList.contains("is-open")) close();
      else open();
    });

    // Close when clicking any link in the dropdown
    dropdown.querySelectorAll("a").forEach((a) => {
      a.addEventListener("click", () => close());
    });

    // Close on ESC
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && dropdown.classList.contains("is-open")) close();
    });

    // Close on resize to desktop
    let lastWidth = window.innerWidth;
    window.addEventListener("resize", () => {
      if (window.innerWidth > 960 && lastWidth <= 960) close();
      lastWidth = window.innerWidth;
    });
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initMobileNav);
  } else {
    initMobileNav();
  }

  // ----- TESTIMONIALS CAROUSEL -----
  // 24 verbatim quotes scraped from the original jfselfdefense.com/programmes/.
  // Source of truth: assets/archive/testimonials.json. Inlined here so the
  // carousel works from file:// previews and never needs a fetch round-trip.
  const TESTIMONIALS = [
    { quote: "The self defense course has been a very fruitful and enjoyable experience! I’ve learnt a lot from the instructors and it’s great that they offer a lot of different techniques and advice on how to protect yourself. They are very friendly and knowledgeable, and I wish I had more time to continue lessons! Thank you for the great lessons!", name: "Ma Yue Ru" },
    { quote: "My 2 boys have been training with Master Jeffrey for a number of years. My eldest son, Jeremy who is now in Polytechnic is still training with him in self-defense. Discipline and respect are core values of Master Jeffrey. The other aspect that I also like very much about his training and as a parent am concern with is his concern for the safety of the students during training sessions. I have also observed that my sons are more confident of themselves which is crucial and important as they grow and mature.", name: "Ms Hogan", role: "Parent" },
    { quote: "It is very good exercise while learning self defense. The lessons are always filled with energy. The trainers are very kind, and they will know if you are unwell. Learning to defend ourselves is very important. It is very fun training with the trainers as they have spirit in everything.", name: "Katerina Lim" },
    { quote: "JF Self Defense is really good and I appreciate being able to learn it. This art is entertaining and really fun. You learn how to defend yourself in a fight and I love it!", name: "Nina Courbet" },
    { quote: "Ryan has helped me develop much more confidence in myself. The training is hard, but it is worth it. Thanks to it I have learned to push my limits further and further.", name: "Alexandre Linot" },
    { quote: "I have been learning at JF Self Defense for a few months now. I really enjoy the great atmosphere and the training. I have learned a lot and lost 6 kilos, while gaining muscle. I recommend this to anyone seeking a good and intense training.", name: "Sebastian Priarollo" },
    { quote: "Ryan has taught me many ways to defend myself from an assault. The training also helps me to be more motivated to do physical exercises and it helps me to stay fit.", name: "Meriadeg Le Neel" },
    { quote: "The training has been very informative and interesting. It covers a wide spectrum of defensive techniques. Both Jeffrey and Ryan are wonderful teachers that push you and help you to reach your potential. I would definitely recommend this course to friends and loved ones.", name: "Nick Olinik" },
    { quote: "I could not find a more efficient and useful training anywhere else, both psychologically and physically.", name: "Alexis Gautier" },
    { quote: "I have been following Ryan’s course for a few months now. It has proven to be very useful. It is very diverse yet everything we learn is useful. The organisation and the length of the class is very good. Every move we learn is simple yet very effective and the physical training at the beginning of the class is very good to develop your mental and physical strength.", name: "Nicolas Selukov" },
    { quote: "Master Jeffrey is very attentive to details and a very experienced individual. With every question raised, he tends to them with great patience and ensure our queries are answered. Attendance to his training lessons, I have seen myself coming up as fitter (both physically and mentally) individual. It also greatly enhances my knowledge on different modes of attacks and ways of countering attacks that could possibly be available. Overall, it is a very enriching experience under his watchful eye and guidance.", name: "Ms Geok Hong" },
    { quote: "The training was very interesting and useful. It is great to get this kind of knowledge and I will definitely share it with my loved ones.", name: "Henna Paivanne-Andersson" },
    { quote: "The lessons are awesome! I wake up so motivated every Sunday morning to attend class. I have always wanted to learn some kind of martial art to give myself a form of self-protection; I got more than I bargain for from Master Jeff. He is a real-life action hero; he is a mixed martial arts coach whom teaches the rope of real self defense using various techniques that is not available in any single martial art form. His emphasis on techniques rather than raw power has enhanced my self-confidence. Indirectly, my stamina and mental power has improved tremendously after 2 months of training!", name: "Koon Leng" },
    { quote: "I got very useful tips for the hotel safety. I also enjoyed the practical session about the different techniques of self-defense.", name: "Marika Arovuo" },
    { quote: "Very useful course; raised many issues I would have not ordinarily considered; made me more security conscious and I will be applying what I learned on future travels and other occasions.", name: "Stina Malik" },
    { quote: "Very practical and useful tips on things I had never before thought of. The hands-on techniques were exciting to learn, and I definitely want to learn more!", name: "Virpi Tervonen" },
    { quote: "Very interesting and informative; some new ideas and I appreciated the practical and applied session particularly.", name: "Joanne Flinn" },
    { quote: "I found the self-defense classes very informational as I will be moving to USA, Philadelphia for my studies. Jeffrey is not only a very detailed Trainer, but he also ensures the safety of the training sessions with precautions. I particularly like his use of humour in order to let us understand certain concepts. The course was certainly useful.", name: "Yingjiao" },
    { quote: "Master Jeffrey’s classes are the kind that you can take home with you because he does not teach a lot at once. He teaches us what we need to learn and he knows how to analyse our stances, posturing, distancing, striking, kicking, etc. By doing so, he knows very well what our weak points are and also our strengths and therefore he knows what to focus on. He has a catch phrase and it goes like this: “There’s no two ways about it”. Overall, he’s a very good teacher, one of the best I ever had.", name: "Dennis Lim", role: "Cavern NS men" },
    { quote: "This workshop had very practical tips and useful simple techniques that every woman should know.", name: "Serene Mak" },
    { quote: "Very useful information shared during the workshop. Really knowledgeable Trainer and some of these skills may ultimately save someone’s life one day.", name: "Jairo C. Lamatim" },
    { quote: "The trainer handled the students very well – he was entertaining and could maintain student’s enthusiasm and interest. The main value of avoiding conflict and staying out of trouble was practical and helpful. Having a highly qualified trainer explain and highlight to students that there is no embarrassment in avoiding conflict and that this was in fact the wiser course of action helped drive the point home that even when one is confident, physical confrontations should be avoided. Yes, I would definitely recommend JF Self-Defense to friends and colleagues who are looking for a practical self-defense course.", name: "Chiew Weihan", role: "Teacher-in-Charge, Teck Whye Secondary School" },
    { quote: "I met Master Jeffrey when he was a security consultant in Indonesia in 2005. He taught me some very simple but effective system of self-defense. The lessons I learnt from him is like equipping me with tools I can use to get me out of immediate danger and tremendously useful for self defense. It is a unique technique and approach and I would recommend those who do not have any fighting background to look him up as he is able to simplify techniques and enjoys sharing his wide martial arts based knowledge.", name: "Purwito Tjandrasa (Wito)", role: "IT / IS Department" },
    { quote: "I greatly enjoyed this self defense course as it not only improved my fitness level and taught me useful techniques to protect myself, it also raised my awareness of safety in any environment I go to. The trainers have been friendly and helpful, sometimes personalizing techniques especially for females. Hence, I really recommend this course for all women regardless whether they are heading overseas or not as self defense is an essential knowledge to master anywhere in the world!", name: "Tong Jiayin" }
  ];

  function escHtml(s) {
    return (s || "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[c]);
  }
  function pad2(n) { return n < 10 ? "0" + n : "" + n; }

  function initTestiCarousel() {
    const root = document.querySelector("[data-testi-carousel]");
    if (!root) return;
    const track = root.querySelector("[data-testi-track]");
    const dotsWrap = document.querySelector("[data-testi-dots]");
    const prev = root.querySelector("[data-testi-prev]");
    const next = root.querySelector("[data-testi-next]");
    const currentEl = document.querySelector("[data-testi-current]");
    const totalEl = document.querySelector("[data-testi-total]");
    if (!track || !dotsWrap) return;

    let index = 0;
    const total = TESTIMONIALS.length;
    if (totalEl) totalEl.textContent = pad2(total);

    // Build slides
    track.innerHTML = TESTIMONIALS.map((t, i) => {
      const roleHtml = t.role ? '<span class="testi-slide-role">— ' + escHtml(t.role) + '</span>' : "";
      return (
        '<article class="testi-slide" data-testi-slide="' + i + '" aria-hidden="' + (i === 0 ? "false" : "true") + '">' +
          '<p class="testi-slide-quote">' + escHtml(t.quote) + '</p>' +
          '<div class="testi-slide-attrib">' +
            '<span class="testi-slide-line"></span>' +
            '<span class="testi-slide-name">' + escHtml(t.name) + '</span>' +
            roleHtml +
          '</div>' +
        '</article>'
      );
    }).join("");

    // Build dots
    dotsWrap.innerHTML = TESTIMONIALS.map((_, i) =>
      '<button class="testi-dot' + (i === 0 ? ' is-active' : '') + '" type="button" data-testi-dot="' + i + '" aria-label="Show testimonial ' + (i + 1) + '"></button>'
    ).join("");

    function render() {
      const slides = track.querySelectorAll(".testi-slide");
      slides.forEach((s, i) => {
        const active = i === index;
        s.classList.toggle("is-active", active);
        s.setAttribute("aria-hidden", active ? "false" : "true");
      });
      dotsWrap.querySelectorAll(".testi-dot").forEach((d, i) => {
        d.classList.toggle("is-active", i === index);
      });
      if (currentEl) currentEl.textContent = pad2(index + 1);
    }
    function go(n) {
      index = ((n % total) + total) % total;
      render();
    }

    prev && prev.addEventListener("click", () => go(index - 1));
    next && next.addEventListener("click", () => go(index + 1));
    dotsWrap.addEventListener("click", (e) => {
      const t = e.target.closest("[data-testi-dot]");
      if (!t) return;
      go(parseInt(t.getAttribute("data-testi-dot"), 10) || 0);
    });

    // Keyboard navigation when carousel is focused
    root.setAttribute("tabindex", "0");
    root.addEventListener("keydown", (e) => {
      if (e.key === "ArrowLeft") { e.preventDefault(); go(index - 1); }
      else if (e.key === "ArrowRight") { e.preventDefault(); go(index + 1); }
    });

    // Touch swipe
    let startX = 0, startY = 0, swiping = false;
    track.addEventListener("touchstart", (e) => {
      if (!e.touches || !e.touches[0]) return;
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      swiping = true;
    }, { passive: true });
    track.addEventListener("touchend", (e) => {
      if (!swiping || !e.changedTouches || !e.changedTouches[0]) return;
      const dx = e.changedTouches[0].clientX - startX;
      const dy = e.changedTouches[0].clientY - startY;
      swiping = false;
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
        go(dx < 0 ? index + 1 : index - 1);
      }
    }, { passive: true });

    render();
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initTestiCarousel);
  } else {
    initTestiCarousel();
  }
})();
