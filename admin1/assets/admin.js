document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.querySelector('.sidebar');
  document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => sidebar?.classList.toggle('open'));
  document.addEventListener('click', (event) => {
    const confirmButton = event.target.closest('[data-confirm]');
    if (confirmButton && !window.confirm(confirmButton.dataset.confirm || 'Continue with this action?')) {
      event.preventDefault();
    }
    if (window.innerWidth <= 760 && sidebar?.classList.contains('open') && !event.target.closest('.sidebar') && !event.target.closest('[data-sidebar-toggle]')) {
      sidebar.classList.remove('open');
    }
  });
  document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      await navigator.clipboard.writeText(button.dataset.copy || '');
      const old = button.textContent;
      button.textContent = 'Copied';
      setTimeout(() => { button.textContent = old; }, 1200);
    });
  });
  document.querySelectorAll('[data-filter-table]').forEach((input) => {
    input.addEventListener('input', () => {
      const needle = input.value.toLowerCase();
      const table = document.querySelector(input.dataset.filterTable);
      table?.querySelectorAll('tbody tr').forEach((row) => {
        row.hidden = !row.textContent.toLowerCase().includes(needle);
      });
    });
  });

  document.querySelectorAll('[data-lottery-control]').forEach((control) => {
    const interval = Math.max(1, Number(control.dataset.interval || 30));
    const initial = Math.max(0, Number(control.dataset.remaining || interval));
    const timer = control.querySelector('[data-control-timer]');
    const startedAt = Date.now();
    let reloading = false;
    const render = () => {
      const elapsed = Math.floor((Date.now() - startedAt) / 1000);
      const left = Math.max(0, initial - elapsed);
      const minutes = String(Math.floor(left / 60)).padStart(2, '0');
      const seconds = String(left % 60).padStart(2, '0');
      if (timer) timer.textContent = minutes + ':' + seconds;
      if (left === 0 && !reloading) {
        reloading = true;
        control.classList.add('round-closing');
        window.setTimeout(() => window.location.reload(), Math.min(1500, interval * 100));
      }
    };
    render();
    window.setInterval(render, 250);
  });
});
