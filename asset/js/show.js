document.addEventListener('DOMContentLoaded', function() {
  const resourceNames = document.querySelectorAll('.resource-name, .title');
  
  resourceNames.forEach(span => {
    // Hebrew Unicode range: \u0590-\u05FF
    if (/[\u0590-\u05FF]/.test(span.textContent)) {
      // Contains Hebrew - apply RTL styling
      span.style.direction = 'rtl';
      span.style.textAlign = 'right';
      span.style.display = 'inline-block';
      span.style.width = '100%';
    } else {
      // No Hebrew - apply LTR styling
      span.style.direction = 'ltr';
      span.style.textAlign = 'left';
      span.style.display = 'inline-block';
      span.style.width = '100%';
    }
  });
});

(function($) {
    $(document).ready(function() {        
        const lgContainer = document.getElementById('itemfiles');

        const inlineGallery = lightGallery(lgContainer, {
            selector: '.media.resource',
            plugins: [lgThumbnail, lgVideo, lgZoom],
            thumbnail: true,
            container: lgContainer,
            hash: false,
            closable: false,
            showMaximizeIcon: true,
            appendSubHtmlTo: '.lg-item',
            captions: true,
            slideDelay: 400,
            allowMediaOverlap: false
        });  

        inlineGallery.openGallery();
    });
  })(jQuery)