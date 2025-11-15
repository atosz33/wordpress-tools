(function($) {
    'use strict';

    let currentPostId = 0;
    let currentPostTitle = '';

    function loadPosts() {
        $('#tm-posts-grid').html('<p class="tm-loading">Loading posts...</p>');

        $.ajax({
            url: tmData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tm_get_posts',
                nonce: tmData.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderPosts(response.data);
                } else {
                    $('#tm-posts-grid').html('<p class="tm-loading">Error loading posts.</p>');
                }
            },
            error: function(xhr, status, error) {
                $('#tm-posts-grid').html('<p class="tm-loading">Error loading posts.</p>');
            }
        });
    }

    function renderPosts(posts) {
        if (posts.length === 0) {
            $('#tm-posts-grid').html(
                '<div class="tm-empty-state">' +
                '<p>No posts found.</p>' +
                '</div>'
            );
            return;
        }

        let html = '';
        posts.forEach(function(post) {
            let thumbnailHtml = '';
            let buttonText = 'Generate';
            let buttonClass = 'tm-generate-btn';

            if (post.thumbnail) {
                thumbnailHtml = '<img src="' + post.thumbnail + '" alt="' + post.title + '">';
                buttonText = 'Regenerate';
                buttonClass = 'tm-regenerate-btn';
            } else {
                thumbnailHtml = '<div class="tm-no-thumbnail">No thumbnail</div>';
            }

            html += '<div class="tm-post-card" data-post-id="' + post.id + '" data-post-title="' + post.title + '">' +
                    '<div class="tm-thumbnail-wrapper">' +
                    thumbnailHtml +
                    '<div class="tm-thumbnail-overlay">' +
                    '<button class="button button-primary ' + buttonClass + '">' + buttonText + '</button>' +
                    '</div>' +
                    '</div>' +
                    '<div class="tm-post-info">' +
                    '<h3 class="tm-post-title"><a href="' + post.edit_link + '" target="_blank">' + post.title + '</a></h3>' +
                    '<button class="button tm-action-btn ' + buttonClass + '">' + buttonText + '</button>' +
                    '</div>' +
                    '</div>';
        });

        $('#tm-posts-grid').html(html);
    }

    function openModal(postId, postTitle) {
        currentPostId = postId;
        currentPostTitle = postTitle;
        
        $('#tm-modal-title').text('Generate Thumbnail for: ' + postTitle);
        $('#tm-search-query').val('');
        $('#tm-images-grid').html('');
        $('#tm-modal').fadeIn(200);
        $('#tm-search-query').focus();
    }

    function closeModal() {
        $('#tm-modal').fadeOut(200);
        currentPostId = 0;
        currentPostTitle = '';
    }

    function searchImages() {
        let query = $('#tm-search-query').val().trim();
        
        if (query === '') {
            alert('Please enter a search query.');
            return;
        }

        $('#tm-images-grid').html('');
        $('#tm-loading').show();
        $('#tm-search-btn').prop('disabled', true).text('Searching...');

        $.ajax({
            url: tmData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tm_search_images',
                nonce: tmData.nonce,
                query: query
            },
            success: function(response) {
                $('#tm-loading').hide();
                $('#tm-search-btn').prop('disabled', false).text('Search Images');

                if (response.success) {
                    renderImages(response.data);
                } else {
                    alert(response.data.message || 'Error searching images.');
                }
            },
            error: function(xhr, status, error) {
                $('#tm-loading').hide();
                $('#tm-search-btn').prop('disabled', false).text('Search Images');
                alert('Error connecting to server.');
            }
        });
    }

    function renderImages(images) {
        if (images.length === 0) {
            $('#tm-images-grid').html('<p class="tm-modal-loading">No images found. Try a different search.</p>');
            return;
        }

        let html = '';
        images.forEach(function(image, index) {
            let sizesJson = JSON.stringify(image.sizes).replace(/"/g, '&quot;');
            
            html += '<div class="tm-image-option" ' +
                    'data-index="' + index + '" ' +
                    'data-sizes="' + sizesJson + '" ' +
                    'data-photographer="' + image.photographer + '" ' +
                    'data-photographer-url="' + image.photographer_url + '" ' +
                    'data-width="' + image.width + '" ' +
                    'data-height="' + image.height + '">' +
                    '<img src="' + image.thumbnail + '" alt="Photo by ' + image.photographer + '">' +
                    '<div class="tm-image-photographer">by ' + image.photographer + '</div>' +
                    '</div>';
        });

        $('#tm-images-grid').html(html);
        setupImageClickHandlers();
    }
    
    function setupImageClickHandlers() {
        $('#tm-images-grid').off('click', '.tm-image-option');
        
        $('#tm-images-grid').on('click', '.tm-image-option', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            let $option = $(this);
            let sizesAttr = $option.attr('data-sizes');
            let photographer = $option.attr('data-photographer');
            let width = $option.attr('data-width');
            let height = $option.attr('data-height');
            
            let sizes;
            try {
                sizes = JSON.parse(sizesAttr.replace(/&quot;/g, '"'));
            } catch(e) {
                alert('Error loading image sizes');
                return;
            }
            
            let sizeOptions = '';
            let sizeLabels = {
                'small': 'Small',
                'medium': 'Medium (Recommended)',
                'large': 'Large',
                'large2x': 'Large 2x',
                'original': 'Original (' + width + 'x' + height + ')'
            };
            
            for (let sizeKey in sizes) {
                if (sizes[sizeKey]) {
                    sizeOptions += '<div class="tm-size-option" data-url="' + sizes[sizeKey] + '" data-photographer="' + photographer + '">' +
                                  '<strong>' + sizeLabels[sizeKey] + '</strong>' +
                                  '</div>';
                }
            }
            
            let sizeDialog = '<div class="tm-size-selector">' +
                           '<h3>Select Image Size</h3>' +
                           '<div class="tm-size-options">' + sizeOptions + '</div>' +
                           '<button class="button tm-cancel-size">Cancel</button>' +
                           '</div>';
            
            $('#tm-images-grid').html(sizeDialog);
            setupSizeClickHandlers();
        });
    }
    
    function setupSizeClickHandlers() {
        $('#tm-images-grid').off('click', '.tm-size-option');
        $('#tm-images-grid').on('click', '.tm-size-option', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            let imageUrl = $(this).attr('data-url');
            let photographer = $(this).attr('data-photographer');
            
            setThumbnail(imageUrl, photographer);
        });
        
        $('#tm-images-grid').off('click', '.tm-cancel-size');
        $('#tm-images-grid').on('click', '.tm-cancel-size', function(e) {
            e.preventDefault();
            e.stopPropagation();
            searchImages();
        });
    }

    function setThumbnail(imageUrl, photographer) {
        
        if (!currentPostId) {
            alert('Error: No post selected.');
            return;
        }

        $('#tm-images-grid').html('<p class="tm-modal-loading">Setting thumbnail...</p>');

        $.ajax({
            url: tmData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tm_set_thumbnail',
                nonce: tmData.nonce,
                post_id: currentPostId,
                image_url: imageUrl,
                photographer: photographer
            },
            success: function(response) {
                if (response.success) {
                    alert('Thumbnail set successfully!');
                    closeModal();
                    loadPosts();
                } else {
                    alert('Error: ' + (response.data.message || 'Failed to set thumbnail.'));
                    searchImages();
                }
            },
            error: function(xhr, status, error) {
                alert('Error connecting to server: ' + error);
                searchImages();
            }
        });
    }

    $(document).ready(function() {
        loadPosts();

        $(document).on('click', '.tm-generate-btn, .tm-regenerate-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            let card = $(this).closest('.tm-post-card');
            let postId = card.data('post-id');
            let postTitle = card.data('post-title');
            
            openModal(postId, postTitle);
        });

        $('.tm-modal-close').on('click', function() {
            closeModal();
        });
        
        $('.tm-modal-overlay').on('click', function() {
            closeModal();
        });

        $('.tm-modal-content').on('click', function(e) {
            e.stopPropagation();
        });

        $('#tm-search-btn').on('click', function() {
            searchImages();
        });

        $('#tm-search-query').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                searchImages();
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#tm-modal').is(':visible')) {
                closeModal();
            }
        });
    });

})(jQuery);
