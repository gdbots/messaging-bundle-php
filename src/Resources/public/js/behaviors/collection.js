define(
[
  'jquery'
],
function ($) {
  'use strict';

  return function(containerId) {
    var $listContainer = $('#' + containerId);
    var $container = $listContainer.parent('.row-collection');

    var getCollectionInfo = function() {
      var index = $listContainer.data('last-index') || $listContainer.children().length;
      var prototypeName = $listContainer.attr('data-prototype-name') || '__name__';
      var html = $listContainer.attr('data-prototype');

      return {
        nextIndex: index,
        prototypeHtml: html,
        prototypeName: prototypeName
      };
    };

    var getCollectionNextItemHtml = function(collectionInfo) {
      return collectionInfo.prototypeHtml.replace(new RegExp(collectionInfo.prototypeName, 'g'), collectionInfo.nextIndex);
    };

    $('.js-btn-add-collection-item-btn', $container).click(function(e) {
      e.preventDefault();

      if ($(this).attr('disabled')) {
        return;
      }

      var rowCountAdd = $listContainer.data('row-count-add') || 1;
      var collectionInfo = getCollectionInfo($listContainer);

      for (var i = 1; i <= rowCountAdd; i++) {
        var nextItemHtml = getCollectionNextItemHtml(collectionInfo);
        collectionInfo.nextIndex++;
        $listContainer.append(nextItemHtml).data('last-index', collectionInfo.nextIndex);
      }

      $listContainer.find('input.position-input').each(function(i, el) {
        $(el).val(i);
      });
    });

    $(document).on('click', '#' + containerId + ' .js-btn-remove-collection-item-btn', function(e) {
      e.preventDefault();

      if ($(this).attr('disabled')) {
        return;
      }

      var closest = '*[data-content]';
      if ($(this).data('closest')) {
          closest = $(this).data('closest');
      }

      $(this).closest(closest).remove();
    });
  };
});