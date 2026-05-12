    </div><!-- /.dash-content -->
  </div><!-- /.dash-main -->
</div><!-- /.dash-shell -->

<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script src="assets/js/theme.js?v=<?= @filemtime(__DIR__ . '/../assets/js/theme.js') ?>"></script>

<!-- Sécurité : on évite le BFCache (cache du back/forward) en forçant un
     reload complet quand la page est restaurée depuis le cache. La
     navigation arrière reste autorisée — seules les pages de connexion
     déclenchent un logout automatique (côté connexion1.php / connexion2.php). -->
<script>
(function () {
  // BFCache : si la page est restaurée depuis le cache, on force un
  // rechargement frais pour récupérer les bons headers et l'état BDD à jour.
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      window.location.reload();
    }
  });
})();
</script>
</body>
</html>
