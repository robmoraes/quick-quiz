import './styles/app.css';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import 'bootstrap';

function pad(value) {
  return String(value).padStart(2, '0');
}

function localOffset(date) {
  const offsetMinutes = -date.getTimezoneOffset();
  const sign = offsetMinutes >= 0 ? '+' : '-';
  const absolute = Math.abs(offsetMinutes);

  return `${sign}${pad(Math.floor(absolute / 60))}:${pad(absolute % 60)}`;
}

function localDateTimeWithOffset(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}${localOffset(date)}`;
}

function localDateTimeForList(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function parseManagerDateTime(value) {
  const trimmed = value.trim();
  if (trimmed === '') {
    return null;
  }

  const parsed = new Date(trimmed);
  if (Number.isNaN(parsed.getTime())) {
    return null;
  }

  return parsed;
}

function initializeCreatedAtFields() {
  document.querySelectorAll('[data-manager-created-at-field]').forEach((field) => {
    const rawValue = field.value.trim();
    if (rawValue === '') {
      field.value = localDateTimeWithOffset(new Date());
      return;
    }

    const date = parseManagerDateTime(rawValue);
    if (date === null) {
      return;
    }

    field.value = localDateTimeWithOffset(date);
  });
}

function initializeCreatedAtDisplays() {
  document.querySelectorAll('[data-manager-created-at-display]').forEach((element) => {
    const date = parseManagerDateTime(element.dataset.managerCreatedAtDisplay ?? '');
    if (date === null) {
      return;
    }

    element.textContent = localDateTimeForList(date);
  });
}

function initializeAiHelpDrawers() {
  document.querySelectorAll('[data-manager-ai-help-drawer]').forEach((drawer) => {
    const toggle = drawer.querySelector('[data-manager-ai-help-toggle]');

    const setOpen = (open) => {
      drawer.classList.toggle('is-open', open);
      document.body.classList.toggle('manager-ai-help-open', open);
      if (toggle !== null) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
    };

    setOpen(drawer.classList.contains('is-open'));
    toggle?.addEventListener('click', () => setOpen(!drawer.classList.contains('is-open')));
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initializeCreatedAtFields();
  initializeCreatedAtDisplays();
  initializeAiHelpDrawers();
});
