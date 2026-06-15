const App = {
  qs: (s, el=document) => el.querySelector(s),
  qsa: (s, el=document) => [...el.querySelectorAll(s)],
  on(el, ev, fn){ el && el.addEventListener(ev, fn); },

  openModal(id) {
    const m = this.qs(id);
    if(!m) return;
    m.classList.add('open');
    m.style.opacity = 0;
    m.style.transform = 'scale(0.95)';
    requestAnimationFrame(() => {
      m.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
      m.style.opacity = 1;
      m.style.transform = 'scale(1)';
    });
  },

  closeModal(id){
    const m = this.qs(id);
    if(!m) return;
    m.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
    m.style.opacity = 0;
    m.style.transform = 'scale(0.95)';
    setTimeout(() => m.classList.remove('open'), 200);
  },

  debounce(fn, delay=300){
    let timeout;
    return (...args) => {
      clearTimeout(timeout);
      timeout = setTimeout(()=> fn(...args), delay);
    };
  },

  searchTable(input, rows){
    const term = (input.value || '').toLowerCase();
    rows.forEach(row => {
      const text = row.textContent.toLowerCase();
      row.style.display = text.includes(term) ? '' : 'none';

      // Highlight match
      [...row.querySelectorAll('td')].forEach(td => {
        td.innerHTML = td.textContent.replace(new RegExp(term, 'gi'), match => `<mark style="background: #fffa00; color:#000">${match}</mark>`);
      });
    });
  },

  toggleTheme(){
    const dark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('theme', dark ? 'dark' : 'light');
  },

  initTheme(){
    if(localStorage.getItem('theme') === 'dark'){
      document.body.classList.add('dark-mode');
    }
  }
};

/* ===========================
   DOM Ready
=========================== */
window.addEventListener('DOMContentLoaded', () => {
  const search = App.qs('#search');
  const rows = App.qsa('tbody tr');

  // Live search with debounce
  if(search){
    App.on(search, 'input', App.debounce(() => App.searchTable(search, rows), 200));
  }

  // Modals
  App.qsa('[data-modal-open]').forEach(btn => 
    App.on(btn, 'click', () => App.openModal(btn.dataset.modalOpen))
  );
  App.qsa('[data-modal-close]').forEach(btn => 
    App.on(btn, 'click', () => App.closeModal(btn.dataset.modalClose))
  );

  // Delete confirmation
  App.qsa('form[data-confirm]').forEach(f =>
    App.on(f, 'submit', e => {
      if(!confirm(f.dataset.confirm)) e.preventDefault();
    })
  );

  // Initialize theme
  App.initTheme();
});
