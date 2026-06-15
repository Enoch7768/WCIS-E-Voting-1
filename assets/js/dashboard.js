const Dashboard = {
  qs: (s, el=document)=> el.querySelector(s),
  qsa: (s, el=document)=> [...el.querySelectorAll(s)],
  on(el, ev, fn){ el && el.addEventListener(ev, fn); },

  /* ===========================
     Notifications / Toasts
  ============================ */
  toast(msg, type='info', duration=3000){
    let container = this.qs('#toast-container');
    if(!container){
      container = document.createElement('div');
      container.id = 'toast-container';
      container.style.position = 'fixed';
      container.style.top = '20px';
      container.style.right = '20px';
      container.style.zIndex = 9999;
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.textContent = msg;
    toast.style.margin = '8px 0';
    toast.style.padding = '12px 20px';
    toast.style.borderRadius = '12px';
    toast.style.color = '#fff';
    toast.style.fontWeight = '600';
    toast.style.minWidth = '200px';
    toast.style.boxShadow = '0 8px 20px rgba(0,0,0,0.3)';
    toast.style.opacity = 0;
    toast.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
    toast.style.transform = 'translateY(-10px)';

    if(type==='success') toast.style.background = 'linear-gradient(180deg,#4cd4a8,#2fa77b)';
    else if(type==='error') toast.style.background = 'linear-gradient(180deg,#ff5b6e,#e03f50)';
    else toast.style.background = 'linear-gradient(180deg,#6e8bff,#3a5dff)';

    container.appendChild(toast);
    requestAnimationFrame(() => {
      toast.style.opacity = 1;
      toast.style.transform = 'translateY(0)';
    });

    setTimeout(()=>{
      toast.style.opacity = 0;
      toast.style.transform = 'translateY(-10px)';
      setTimeout(()=> toast.remove(), 300);
    }, duration);
  },

  /* ===========================
     Sortable Table
  ============================ */
  sortTable(tableId, colIndex, numeric=false){
    const table = this.qs(`#${tableId}`);
    if(!table) return;

    const tbody = table.tBodies[0];
    const rows = [...tbody.rows];
    const asc = table.dataset.sortOrder !== 'asc';
    table.dataset.sortOrder = asc ? 'asc' : 'desc';

    rows.sort((a,b) => {
      let aText = a.cells[colIndex].textContent.trim();
      let bText = b.cells[colIndex].textContent.trim();
      if(numeric){
        aText = parseFloat(aText) || 0;
        bText = parseFloat(bText) || 0;
      }
      return asc ? aText.localeCompare(bText, undefined, {numeric:true}) : bText.localeCompare(aText, undefined, {numeric:true});
    });

    rows.forEach(r => tbody.appendChild(r));
  },

  /* ===========================
     Chart Rendering (Chart.js required)
  ============================ */
  renderChart(canvasId, type='bar', data={}, options={}){
    const ctx = this.qs(`#${canvasId}`);
    if(!ctx) return;
    return new Chart(ctx, { type, data, options });
  },

  /* ===========================
     Live Table Search
  ============================ */
  searchTable(inputId, tableId){
    const input = this.qs(`#${inputId}`);
    const table = this.qs(`#${tableId}`);
    if(!input || !table) return;

    input.addEventListener('input', Dashboard.debounce(()=>{
      const term = input.value.toLowerCase();
      [...table.tBodies[0].rows].forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
      });
    }, 200));
  },

  /* ===========================
     Utility: Debounce
  ============================ */
  debounce(fn, delay=300){
    let timeout;
    return (...args) => {
      clearTimeout(timeout);
      timeout = setTimeout(()=> fn(...args), delay);
    };
  }
};

/* ===========================
   DOM Ready
=========================== */
window.addEventListener('DOMContentLoaded', ()=>{
  // Example: Initialize search
  Dashboard.searchTable('search', 'users-table');

  // Example: Sortable table headers
  Dashboard.qsa('#users-table th').forEach((th,i)=>{
    Dashboard.on(th,'click',()=> Dashboard.sortTable('users-table', i));
  });

  // Example: Show a welcome toast
  Dashboard.toast('Welcome to your dashboard!', 'success', 4000);
});
