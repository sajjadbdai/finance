  </div><!-- /content -->
</div><!-- /main -->
<script>
// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(e) {
  const sidebar = document.getElementById('sidebar');
  if (sidebar && window.innerWidth <= 600) {
    if (!sidebar.contains(e.target) && !e.target.classList.contains('menu-toggle')) {
      sidebar.classList.remove('open');
    }
  }
});
</script>
</body>
</html>
