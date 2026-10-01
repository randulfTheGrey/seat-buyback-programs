<script>
  $('.buyback-confirm-submitter').on('click', function (event) {
    var button = this;

    event.preventDefault();
    bootbox.confirm($(button).data('confirm-message'), function (confirmed) {
      if (confirmed) button.form.requestSubmit(button);
    });
  });
</script>
