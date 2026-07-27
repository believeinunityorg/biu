"use client";

import React, { useState } from "react";
import { router, usePage } from "@inertiajs/react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Coins, ArrowRightLeft, Banknote } from "lucide-react";

declare global {
  function route(name: string, params?: Record<string, unknown>): string;
}

interface Listing {
  id: number;
  title: string;
  points_value: number;
  nonprofit?: { name: string };
}

interface RequestTradeModalProps {
  requestedListing: Listing;
  myListings: { id: number; title: string; points_value: number }[];
  onClose: () => void;
}

type OfferType = "trade" | "points";

export default function RequestTradeModal({
  requestedListing,
  myListings,
  onClose,
}: RequestTradeModalProps) {
  const [offerType, setOfferType] = useState<OfferType>(myListings.length > 0 ? "trade" : "points");
  const [returnListingId, setReturnListingId] = useState<string>("");
  const [extraPoints, setExtraPoints] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const page = usePage();
  const auth = page.props?.auth as {
    user?: {
      believe_points?: number
      gifted_believe_points?: number
      purchased_believe_points?: number
    }
  };
  // Barter spends purchased BP only (Gift BP is gift-card-only).
  const balance = Number(
    auth?.user?.purchased_believe_points ??
      Math.max(
        0,
        Number(auth?.user?.believe_points ?? 0) - Number(auth?.user?.gifted_believe_points ?? 0)
      )
  );
  const requiredPoints = requestedListing.points_value;
  const canPayPointsOnly = balance >= requiredPoints;
  const rawErrors = (page.props?.errors as Record<string, string | string[]> | undefined) ?? {};
  const getFirst = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
  const pointsError = getFirst(rawErrors.points_offer) ?? getFirst(rawErrors.points) ?? null;
  const otherError = Object.keys(rawErrors).length > 0 ? getFirst(Object.values(rawErrors)[0]) : null;

  const returnListing = myListings.find((l) => l.id === Number(returnListingId));
  const baseDelta = returnListing
    ? requestedListing.points_value - returnListing.points_value
    : null;
  const extra = parseInt(extraPoints, 10) || 0;
  const previewDelta = baseDelta !== null ? baseDelta + extra : null;

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);

    if (offerType === "points") {
      router.post(route("barter.request-trade"), {
        requested_listing_id: requestedListing.id,
        points_offer: requiredPoints,
      }, {
        onFinish: () => setSubmitting(false),
        onSuccess: () => onClose(),
        onError: () => setSubmitting(false),
      });
      return;
    }

    if (!returnListingId) {
      setSubmitting(false);
      return;
    }
    router.post(route("barter.request-trade"), {
      requested_listing_id: requestedListing.id,
      return_listing_id: Number(returnListingId),
      extra_points: extra || 0,
    }, {
      onFinish: () => setSubmitting(false),
      onSuccess: () => onClose(),
      onError: () => setSubmitting(false),
    });
  };

  const canSubmit =
    offerType === "points"
      ? canPayPointsOnly
      : Boolean(returnListingId);

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90vh] w-[calc(100vw-2rem)] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Request Trade</DialogTitle>
          <DialogDescription className="text-muted-foreground">
            You want: <strong className="text-foreground">{requestedListing.title}</strong> ({requestedListing.points_value} pts) from {requestedListing.nonprofit?.name}.
            Choose to either trade with one of your listings or pay with Believe Points.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="space-y-4">
          {/* Offer type: Trade or Points */}
          <div className="space-y-2">
            <Label>I want to</Label>
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => setOfferType("trade")}
                className={`flex items-center justify-center gap-2 rounded-lg border-2 px-3 py-2.5 text-sm font-medium transition-colors ${
                  offerType === "trade"
                    ? "border-primary bg-primary/10 text-primary"
                    : "border-border bg-muted/30 text-muted-foreground hover:border-muted-foreground/30"
                }`}
              >
                <ArrowRightLeft className="h-4 w-4" />
                Trade
              </button>
              <button
                type="button"
                onClick={() => setOfferType("points")}
                className={`flex items-center justify-center gap-2 rounded-lg border-2 px-3 py-2.5 text-sm font-medium transition-colors ${
                  offerType === "points"
                    ? "border-primary bg-primary/10 text-primary"
                    : "border-border bg-muted/30 text-muted-foreground hover:border-muted-foreground/30"
                }`}
              >
                <Banknote className="h-4 w-4" />
                Offer points
              </button>
            </div>
          </div>

          {offerType === "trade" ? (
            <>
              <div>
                <Label>Your listing to offer</Label>
                <Select value={returnListingId} onValueChange={setReturnListingId} required={offerType === "trade"}>
                  <SelectTrigger className="mt-1 min-h-10">
                    <SelectValue placeholder="Select a listing" />
                  </SelectTrigger>
                  <SelectContent>
                    {myListings.map((l) => (
                      <SelectItem key={l.id} value={String(l.id)}>
                        {l.title} ({l.points_value} pts)
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Extra Believe Points (optional)</Label>
                <Input
                  type="number"
                  min={0}
                  value={extraPoints}
                  onChange={(e) => setExtraPoints(e.target.value)}
                  placeholder="0"
                  className="mt-1 min-h-10"
                />
              </div>
              {previewDelta !== null && (
                <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                  <Coins className="h-4 w-4 shrink-0 text-primary" />
                  {previewDelta > 0
                    ? `You pay ${previewDelta} points to them`
                    : previewDelta < 0
                      ? `They pay ${Math.abs(previewDelta)} points to you`
                      : "Even swap, no points movement"}
                </p>
              )}
            </>
          ) : (
            <div className="rounded-lg border border-border bg-muted/30 p-4 space-y-2">
              <p className="text-sm font-medium flex items-center gap-2">
                <Coins className="h-4 w-4 text-primary" />
                Pay {requiredPoints} Believe Points
              </p>
              <p className="text-xs text-muted-foreground">
                Your balance: <strong className="text-foreground">{balance.toLocaleString()}</strong> pts
                {!canPayPointsOnly && (
                  <span className="block text-destructive mt-1">You need at least {requiredPoints} points to send this request.</span>
                )}
              </p>
              {pointsError && (
                <p className="text-sm text-destructive">{pointsError}</p>
              )}
            </div>
          )}

          {otherError && !pointsError && (
            <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
              {otherError}
            </div>
          )}

          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" onClick={onClose} className="min-h-10 touch-manipulation">
              Cancel
            </Button>
            <Button type="submit" disabled={!canSubmit || submitting} className="min-h-10 touch-manipulation">
              {submitting ? "Sending…" : "Send Request"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
