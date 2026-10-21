// Support Center JavaScript
document.addEventListener('DOMContentLoaded', function() {
  // Sidebar toggle (mobile)
  const sidebarToggle = document.getElementById('supportSidebarToggle');
  const sidebar = document.getElementById('supportSidebar');
  const overlay = document.getElementById('supportSidebarOverlay');
  const sidebarClose = document.getElementById('supportSidebarClose');

  function openSidebar() {
    if (sidebar) sidebar.classList.add('show');
    if (overlay) overlay.classList.add('show');
    document.body.classList.add('support-sidebar-open');
  }
  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('show');
    if (overlay) overlay.classList.remove('show');
    document.body.classList.remove('support-sidebar-open');
  }

  if (sidebarToggle) sidebarToggle.addEventListener('click', openSidebar);
  if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);

  // Knowledge Base nav items
  const kbNavItems = document.querySelectorAll('.support-kb-item');
  kbNavItems.forEach(item => {
    item.addEventListener('click', function() {
      kbNavItems.forEach(i => i.classList.remove('active'));
      this.classList.add('active');
    });
  });

  // Ticket tabs
  const ticketTabs = document.querySelectorAll('.support-tickets-tabs .nav-link, .support-tab');
  ticketTabs.forEach(tab => {
    tab.addEventListener('click', function(e) {
      e.preventDefault();
      ticketTabs.forEach(t => t.classList.remove('active'));
      this.classList.add('active');
    });
  });

  // Ticket item click - open detail modal
  const ticketItems = document.querySelectorAll('.support-ticket-item, .ticket-item');
  ticketItems.forEach(item => {
    item.addEventListener('click', function() {
      const modal = document.getElementById('ticketDetailModal');
      if (modal && window.bootstrap) {
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
      }
    });
  });

  // New ticket button
  const newTicketBtn = document.getElementById('newTicketBtn');
  if (newTicketBtn) {
    newTicketBtn.addEventListener('click', function() {
      const modal = document.getElementById('newTicketModal');
      if (modal && window.bootstrap) {
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
      }
    });
  }

  // Article card click
  const articleCards = document.querySelectorAll('.support-article-card, .article-card');
  articleCards.forEach(card => {
    card.addEventListener('click', function() {
      // Open article in modal or new page
      const title = this.querySelector('.article-title, h6, h5');
      if (title) {
        console.log('Article clicked:', title.textContent);
      }
    });
  });

  // Search functionality
  const searchInput = document.querySelector('.support-search input, .support-header input[type="search"]');
  if (searchInput) {
    searchInput.addEventListener('input', function() {
      const query = this.value.toLowerCase();
      // Filter tickets/articles based on query
      const items = document.querySelectorAll('.support-ticket-item, .support-article-card');
      items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(query) ? '' : 'none';
      });
    });
  }
});
