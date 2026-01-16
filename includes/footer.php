<script>
        // Sidebar Toggle Script Global
        const toggleBtn = document.getElementById("menu-toggle");
        if(toggleBtn){
            toggleBtn.addEventListener("click", function(e) {
                e.preventDefault();
                document.body.classList.toggle("toggled");
            });
        }
    </script>
</body>
</html>