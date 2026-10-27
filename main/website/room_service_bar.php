<?php
/**
 * A thin bar at the top of the menu and cart while the guest ordered from a hotel room's QR code ($qr_room from
 * header.php): "Room service · Room 104", so they know the food comes to their room. Nothing otherwise.
 */
if (empty($qr_room)) return;
?>
<div class="rg-roombar" role="status">
  <i class="fa-solid fa-bell-concierge" aria-hidden="true"></i>
  <span><b>Room service</b> · Room <?php echo htmlspecialchars($qr_room['room'], ENT_QUOTES, 'UTF-8'); ?><?php
    echo $qr_room['charge'] ? ' · add it to your room bill' : ''; ?></span>
</div>
<style>
.rg-roombar { display: flex; align-items: center; gap: 8px; justify-content: center; padding: 8px 12px; font-size: 12.5px;
  background: linear-gradient(135deg, var(--primary-red, #e17055), var(--dark-red, #d63031)); color: #fff; font-family: 'Poppins', sans-serif; }
.rg-roombar b { font-weight: 700; }
</style>
