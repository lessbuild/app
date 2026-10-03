<?php echo strip_tags($header ?? ''); ?>


<?php echo strip_tags($slot); ?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($subcopy)): ?>

<?php echo strip_tags($subcopy); ?>

<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<?php echo strip_tags($footer ?? ''); ?>

<?php /**PATH /mnt/volume_nyc1_1789401255960/platform-v3/api/vendor/laravel/framework/src/Illuminate/Mail/resources/views/text/layout.blade.php ENDPATH**/ ?>