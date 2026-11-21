document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-auto-submit]').forEach(el => el.addEventListener('change', () => el.form?.requestSubmit()));
  document.querySelectorAll('form[data-confirm]').forEach(el => el.addEventListener('submit', event => {
    if (!window.confirm(el.dataset.confirm || 'Are you sure?')) event.preventDefault();
  }));
  document.querySelector('[data-toggle-nav]')?.addEventListener('click', () => document.body.classList.toggle('show-sidebar'));
  document.querySelectorAll('.sidebar nav a').forEach(el => el.addEventListener('click', () => document.body.classList.remove('show-sidebar')));
  document.getElementById('property-search')?.addEventListener('input', event => {
    const value = event.target.value.toLowerCase().trim();
    document.querySelectorAll('[data-property]').forEach(el => { el.hidden = !el.dataset.property.includes(value); });
  });
});
