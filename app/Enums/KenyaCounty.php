<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum KenyaCounty: string implements HasLabel
{
    case Mombasa = 'mombasa';
    case Kwale = 'kwale';
    case Kilifi = 'kilifi';
    case TanaRiver = 'tana_river';
    case Lamu = 'lamu';
    case TaitaTaveta = 'taita_taveta';
    case Garissa = 'garissa';
    case Wajir = 'wajir';
    case Mandera = 'mandera';
    case Marsabit = 'marsabit';
    case Isiolo = 'isiolo';
    case Meru = 'meru';
    case TharakaNithi = 'tharaka_nithi';
    case Embu = 'embu';
    case Kitui = 'kitui';
    case Machakos = 'machakos';
    case Makueni = 'makueni';
    case Nyandarua = 'nyandarua';
    case Nyeri = 'nyeri';
    case Kirinyaga = 'kirinyaga';
    case Muranga = 'muranga';
    case Kiambu = 'kiambu';
    case Turkana = 'turkana';
    case WestPokot = 'west_pokot';
    case Samburu = 'samburu';
    case TransNzoia = 'trans_nzoia';
    case UasinGishu = 'uasin_gishu';
    case ElgeyoMarakwet = 'elgeyo_marakwet';
    case Nandi = 'nandi';
    case Baringo = 'baringo';
    case Laikipia = 'laikipia';
    case Nakuru = 'nakuru';
    case Narok = 'narok';
    case Kajiado = 'kajiado';
    case Kericho = 'kericho';
    case Bomet = 'bomet';
    case Kakamega = 'kakamega';
    case Vihiga = 'vihiga';
    case Bungoma = 'bungoma';
    case Busia = 'busia';
    case Siaya = 'siaya';
    case Kisumu = 'kisumu';
    case HomaBay = 'homa_bay';
    case Migori = 'migori';
    case Kisii = 'kisii';
    case Nyamira = 'nyamira';
    case Nairobi = 'nairobi';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mombasa => 'Mombasa',
            self::Kwale => 'Kwale',
            self::Kilifi => 'Kilifi',
            self::TanaRiver => 'Tana River',
            self::Lamu => 'Lamu',
            self::TaitaTaveta => 'Taita Taveta',
            self::Garissa => 'Garissa',
            self::Wajir => 'Wajir',
            self::Mandera => 'Mandera',
            self::Marsabit => 'Marsabit',
            self::Isiolo => 'Isiolo',
            self::Meru => 'Meru',
            self::TharakaNithi => 'Tharaka Nithi',
            self::Embu => 'Embu',
            self::Kitui => 'Kitui',
            self::Machakos => 'Machakos',
            self::Makueni => 'Makueni',
            self::Nyandarua => 'Nyandarua',
            self::Nyeri => 'Nyeri',
            self::Kirinyaga => 'Kirinyaga',
            self::Muranga => 'Murang\'a',
            self::Kiambu => 'Kiambu',
            self::Turkana => 'Turkana',
            self::WestPokot => 'West Pokot',
            self::Samburu => 'Samburu',
            self::TransNzoia => 'Trans Nzoia',
            self::UasinGishu => 'Uasin Gishu',
            self::ElgeyoMarakwet => 'Elgeyo Marakwet',
            self::Nandi => 'Nandi',
            self::Baringo => 'Baringo',
            self::Laikipia => 'Laikipia',
            self::Nakuru => 'Nakuru',
            self::Narok => 'Narok',
            self::Kajiado => 'Kajiado',
            self::Kericho => 'Kericho',
            self::Bomet => 'Bomet',
            self::Kakamega => 'Kakamega',
            self::Vihiga => 'Vihiga',
            self::Bungoma => 'Bungoma',
            self::Busia => 'Busia',
            self::Siaya => 'Siaya',
            self::Kisumu => 'Kisumu',
            self::HomaBay => 'Homa Bay',
            self::Migori => 'Migori',
            self::Kisii => 'Kisii',
            self::Nyamira => 'Nyamira',
            self::Nairobi => 'Nairobi',
        };
    }
}
