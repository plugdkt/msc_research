/**
 * MSC Research Embed Widget Auto-resize Helper
 * Listens for height resize messages from MSC Research embed iframes
 * and automatically adjusts iframe height to prevent double scrollbars.
 *
 * Usage:
 * <iframe class="msc-research-widget" src="https://www.medsci.up.ac.th/msc_researchv2/embed.php?type=recent" width="100%" height="450" frameborder="0" style="border:none; overflow:hidden;"></iframe>
 * <script src="https://www.medsci.up.ac.th/msc_researchv2/embed.js"></script>
 */
(function() {
    window.addEventListener('message', function(event) {
        if (!event.data || event.data.type !== 'msc-widget-resize') return;
        
        var iframes = document.querySelectorAll('iframe');
        for (var i = 0; i < iframes.length; i++) {
            var f = iframes[i];
            try {
                if (f.contentWindow === event.source || (f.src && f.src.indexOf('embed.php') !== -1)) {
                    var targetHeight = parseInt(event.data.height, 10);
                    if (targetHeight && targetHeight > 40) {
                        var currentHeight = parseInt(f.style.height, 10) || f.offsetHeight;
                        if (Math.abs(currentHeight - targetHeight) >= 2) {
                            f.style.height = targetHeight + 'px';
                        }
                    }
                }
            } catch (e) {}
        }
    });
})();
