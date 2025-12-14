// Small helpers for modals and mobile nav toggling
(function(){
  function toggleModal(id){
    var el = document.getElementById(id);
    if(!el) return;
    el.classList.toggle('show');
  }

  function setupNavToggle(){
    var btn = document.querySelector('.nav-toggle');
    var nav = document.getElementById('primary-navigation');
    if(!btn || !nav) return;
    btn.addEventListener('click', function(){
      var expanded = this.getAttribute('aria-expanded') === 'true';
      this.setAttribute('aria-expanded', !expanded);
      nav.classList.toggle('open');
    });
  }

  // Expose for inline onclicks used in markup
  window.toggleModal = toggleModal;

  document.addEventListener('DOMContentLoaded', function(){
    setupNavToggle();
  });
})();
