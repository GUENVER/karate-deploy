<?php
/**
 * _acces_lishan.php — Overlay du CRM : pilotage des accès à l'espace adhérents.
 * Injecté avant </body> par index.php (même mécanisme que _postits.php).
 *
 * Deux actions, signées en HMAC et envoyées au endpoint dev.adherents :
 *   - global     : synchronise tous les adhérents éligibles de la saison courante
 *   - individuel : synchronise l'adhérent dont la fiche est ouverte (crm_id)
 *
 * Le secret n'est jamais exposé au navigateur : un proxy local (_acces_sync.php)
 * signé côté serveur est appelé en same-origin par le bouton.
 */
?>
<style>
#al-fab{position:fixed;right:18px;bottom:18px;z-index:3500;background:#475569;color:#fff;border:none;
  border-radius:999px;padding:12px 18px;font:600 14px/1 'Segoe UI',sans-serif;cursor:pointer;
  box-shadow:0 8px 22px rgba(0,0,0,.25);display:flex;align-items:center;gap:8px}
#al-fab:hover{background:#3a4658}
#al-fab .al-spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;display:none;animation:al-rot .7s linear infinite}
#al-fab.busy .al-spin{display:inline-block}
@keyframes al-rot{to{transform:rotate(360deg)}}
.al-fiche-btn{background:#475569;color:#fff;border:none;border-radius:8px;padding:9px 14px;
  font:600 13px/1 'Segoe UI',sans-serif;cursor:pointer;margin:6px 4px 0 0}
.al-fiche-btn:hover{background:#3a4658}
#al-toast{position:fixed;left:50%;bottom:74px;transform:translateX(-50%);z-index:3600;background:#1e2530;
  color:#fff;padding:11px 18px;border-radius:8px;font:500 13px/1.4 'Segoe UI',sans-serif;max-width:90vw;
  box-shadow:0 8px 22px rgba(0,0,0,.3);opacity:0;transition:opacity .25s;pointer-events:none}
#al-toast.show{opacity:1}
</style>

<button id="al-fab" title="Synchroniser les accès à l'espace adhérents">
  <span class="al-spin"></span><span class="al-lbl">Synchroniser les accès</span>
</button>
<div id="al-toast"></div>

<script>
(function(){
  var fab = document.getElementById('al-fab');
  var toast = document.getElementById('al-toast');
  function showToast(msg, ok){
    toast.textContent = msg;
    toast.style.background = ok===false ? '#9b3030' : '#1e2530';
    toast.classList.add('show');
    setTimeout(function(){ toast.classList.remove('show'); }, 5000);
  }
  function call(scope, crmId){
    var fd = new FormData();
    fd.append('scope', scope);
    if (crmId) fd.append('crm_id', crmId);
    return fetch('_acces_sync.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); });
  }

  fab.addEventListener('click', function(){
    if (fab.classList.contains('busy')) return;
    if (!confirm("Synchroniser l'accès de tous les adhérents de la saison en cours ?")) return;
    fab.classList.add('busy'); fab.querySelector('.al-lbl').textContent = 'Synchronisation…';
    call('all').then(function(d){
      fab.classList.remove('busy'); fab.querySelector('.al-lbl').textContent = 'Synchroniser les accès';
      if (d && d.ok){
        var r = d.result || {};
        showToast('Accès synchronisés : ' + (r.crees||0) + ' créé(s), ' + (r.maj||0) + ' à jour, ' + (r.desactives||0) + ' retiré(s).', true);
      } else {
        showToast((d && d.err) ? d.err : 'Échec de la synchronisation.', false);
      }
    }).catch(function(){
      fab.classList.remove('busy'); fab.querySelector('.al-lbl').textContent = 'Synchroniser les accès';
      showToast('Erreur réseau.', false);
    });
  });

  window.alDonnerAcces = function(crmId){
    if (!crmId){ showToast("Enregistrez d'abord la fiche.", false); return; }
    if (!confirm("Donner / mettre à jour l'accès de cet adhérent à l'espace ?")) return;
    call('one', crmId).then(function(d){
      if (d && d.ok){
        var r = d.result || {};
        if (r.desactives) showToast("Adhérent non éligible cette saison : accès retiré.", true);
        else if (r.crees) showToast("Accès créé pour cet adhérent.", true);
        else showToast("Accès mis à jour.", true);
      } else {
        showToast((d && d.err) ? d.err : "Échec.", false);
      }
    }).catch(function(){ showToast('Erreur réseau.', false); });
  };

  function currentCrmId(){
    var el = document.querySelector('[name="id"],#ad-id,#fiche-id,[data-current-id]');
    if (el) return el.value || el.getAttribute('data-current-id');
    if (window.currentId) return window.currentId;
    if (window.selectedId) return window.selectedId;
    return '';
  }
  function injectFicheButton(){
    var closeBtn = Array.prototype.find ? Array.prototype.find.call(document.querySelectorAll('button'), function(b){ return /Fermer/.test(b.textContent); }) : null;
    if (!closeBtn || document.getElementById('al-fiche-btn')) return;
    var b = document.createElement('button');
    b.id = 'al-fiche-btn'; b.className = 'al-fiche-btn';
    b.textContent = '🔑 Donner accès espace';
    b.onclick = function(){ window.alDonnerAcces(currentCrmId()); };
    closeBtn.parentNode.insertBefore(b, closeBtn);
  }
  setInterval(injectFicheButton, 1200);
})();
</script>
