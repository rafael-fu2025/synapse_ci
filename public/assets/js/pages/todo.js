// Todo App JavaScript
document.addEventListener('DOMContentLoaded', function() {
  // Sidebar toggle (mobile)
  const sidebarToggle = document.getElementById('todoSidebarToggle');
  const sidebar = document.getElementById('todoSidebar');
  const overlay = document.getElementById('todoSidebarOverlay');
  const sidebarClose = document.getElementById('todoSidebarClose');

  function openSidebar() {
    if (sidebar) sidebar.classList.add('show');
    if (overlay) overlay.classList.add('show');
    document.body.classList.add('todo-sidebar-open');
  }
  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('show');
    if (overlay) overlay.classList.remove('show');
    document.body.classList.remove('todo-sidebar-open');
  }

  if (sidebarToggle) sidebarToggle.addEventListener('click', openSidebar);
  if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);

  // Task checkbox toggle
  const todoItems = document.querySelectorAll('.todo-item');
  todoItems.forEach(item => {
    const checkbox = item.querySelector('.todo-checkbox');
    if (checkbox) {
      checkbox.addEventListener('change', function() {
        item.classList.toggle('completed', this.checked);
      });
    }

    // Click on item to open view modal
    item.addEventListener('click', function(e) {
      if (e.target.closest('.todo-checkbox') || e.target.closest('.todo-item-star') || e.target.closest('.todo-item-more')) return;
      const modal = document.getElementById('viewTaskModal');
      if (modal && window.bootstrap) {
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
      }
    });
  });

  // Section toggle (collapse/expand)
  const sectionToggles = document.querySelectorAll('.todo-section-toggle');
  sectionToggles.forEach(btn => {
    btn.addEventListener('click', function() {
      const section = this.closest('.todo-section');
      if (section) {
        section.classList.toggle('collapsed');
        const icon = this.querySelector('i');
        if (icon) {
          if (section.classList.contains('collapsed')) {
            icon.classList.remove('bi-chevron-down');
            icon.classList.add('bi-chevron-right');
          } else {
            icon.classList.remove('bi-chevron-right');
            icon.classList.add('bi-chevron-down');
          }
        }
      }
    });
  });

  // View toggle (list/board)
  const viewBtns = document.querySelectorAll('.todo-view-btn');
  const todoList = document.querySelector('.todo-list');
  viewBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      viewBtns.forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      if (todoList) {
        if (this.dataset.view === 'board') {
          todoList.classList.add('board-view');
        } else {
          todoList.classList.remove('board-view');
        }
      }
    });
  });

  // Filter buttons
  const filterBtns = document.querySelectorAll('.todo-filter-btn');
  filterBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      filterBtns.forEach(b => b.classList.remove('active'));
      this.classList.add('active');
    });
  });

  // Star toggle
  const starBtns = document.querySelectorAll('.todo-item-star, .todo-view-star');
  starBtns.forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      this.classList.toggle('starred');
      const icon = this.querySelector('i');
      if (icon) {
        if (this.classList.contains('starred')) {
          icon.classList.remove('bi-star');
          icon.classList.add('bi-star-fill');
        } else {
          icon.classList.remove('bi-star-fill');
          icon.classList.add('bi-star');
        }
      }
    });
  });

  // Quick add task
  const quickAddBtn = document.getElementById('quickAddBtn');
  const quickAddForm = document.getElementById('quickAddForm');
  const cancelQuickAdd = document.getElementById('cancelQuickAdd');

  if (quickAddBtn && quickAddForm) {
    quickAddBtn.addEventListener('click', function() {
      quickAddForm.style.display = 'block';
      quickAddBtn.style.display = 'none';
      const input = quickAddForm.querySelector('input');
      if (input) input.focus();
    });
  }
  if (cancelQuickAdd && quickAddForm && quickAddBtn) {
    cancelQuickAdd.addEventListener('click', function() {
      quickAddForm.style.display = 'none';
      quickAddBtn.style.display = 'block';
    });
  }

  // Nav item active state
  const todoNavItems = document.querySelectorAll('.todo-nav-item');
  todoNavItems.forEach(item => {
    item.addEventListener('click', function() {
      todoNavItems.forEach(i => i.classList.remove('active'));
      this.classList.add('active');
    });
  });
});
